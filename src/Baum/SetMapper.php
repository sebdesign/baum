<?php
namespace Baum;

use \Closure;
use Illuminate\Support\Contracts\ArrayableInterface;
use Baum\Node;

class SetMapper {

  /**
  * Node instance for reference
  *
  * @var \Baum\Node
  */
  protected $node = NULL;

  /**
   * Children key name
   *
   * @var string
   */
  protected $childrenKeyName = 'children';

  /**
   * Create a new \Baum\SetBuilder class instance.
   *
   * @param   \Baum\Node      $node
   * @return  void
   */
  public function __construct($node, $childrenKeyName = 'children') {
    $this->node = $node;

    $this->childrenKeyName = $childrenKeyName;
  }

  /**
   * Maps a tree structure into the database. Unguards & wraps in transaction.
   *
   * @param   array|\Illuminate\Support\Contracts\ArrayableInterface
   * @return  boolean
   */
  public function map($nodeList) {
    $self = $this;

    return $this->wrapInTransaction(function() use ($self, $nodeList) {
      forward_static_call(array(get_class($self->node), 'unguard'));

      $result = $self->mapTree($nodeList);

      forward_static_call(array(get_class($self->node), 'reguard'));

      return $result;
    });
  }

  /**
   * Update a tree structure in the database. Unguards & wraps in transaction.
   *
   * @param   array|\Illuminate\Support\Contracts\ArrayableInterface
   * @return  boolean
   */
  public function updateMap($nodeList) {
    $self = $this;

    return $this->wrapInTransaction(function() use ($self, $nodeList) {
      forward_static_call(array(get_class($self->node), 'unguard'));

      $result = true;

      try {
        $flattenTree = $self->flattenNestable($nodeList);

        foreach ($flattenTree as $node) {
          $self->node->flushEventListeners();
          $model = $self->node->findOrFail(array_get($node, $self->node->getKeyName()));
          $model->update($node);
        }
      } catch (\Exception $e) {
          $result = false;
      }

      forward_static_call(array(get_class($self->node), 'reguard'));

      return $result;
    });
  }

  /**
   * Flattens an array to contain 'id', 'lft', 'rgt', 'depth', 'parent_id' as a valid tree
   * @param $nestableArray
   * @param mixed $parent_id
   * @param int $depth
   * @param int $bound
   * @return array
   */
  public function flattenNestable($nodeList, $parent_id = null, $depth = 0, &$bound = 0)
  {
    $nodes = array();

    foreach ($nodeList as $node) {
      $lft = ++$bound;

      $children = array_get($node, $this->childrenKeyName, []);

      if (!empty($children)) {
        $children = $this->flattenNestable($children, array_get($node, $this->node->getKeyName()), $depth + 1, $bound);
      }

      $rgt = ++$bound;

      $nodes[] = array(
        $this->node->getKeyName() => array_get($node, $this->node->getKeyName()),
        $this->node->getParentColumnName() => $parent_id,
        $this->node->getDepthColumnName() => $depth,
        $this->node->getLeftColumnName() => $lft,
        $this->node->getRightColumnName() => $rgt,
      );

      $nodes = array_merge($nodes, $children);
    }

    return $nodes;
  }

  /**
   * Maps a tree structure into the database without unguarding nor wrapping
   * inside a transaction.
   *
   * @param   array|\Illuminate\Support\Contracts\ArrayableInterface
   * @return  boolean
   */
  public function mapTree($nodeList) {
    $tree = $nodeList instanceof ArrayableInterface ? $nodeList->toArray() : $nodeList;

    $affectedKeys = array();

    $result = $this->mapTreeRecursive($tree, $this->node->getKey(), $affectedKeys);

    if ( $result && count($affectedKeys) > 0 )
      $this->deleteUnaffected($affectedKeys);

    return $result;
  }

  /**
   * Returns the children key name to use on the mapping array
   *
   * @return string
   */
  public function getChildrenKeyName() {
    return $this->childrenKeyName;
  }

  /**
   * Maps a tree structure into the database
   *
   * @param   array   $tree
   * @param   mixed   $parent
   * @return  boolean
   */
  protected function mapTreeRecursive(array $tree, $parentKey = null, &$affectedKeys = array()) {
    // For every attribute entry: We'll need to instantiate a new node either
    // from the database (if the primary key was supplied) or a new instance. Then,
    // append all the remaining data attributes (including the `parent_id` if
    // present) and save it. Finally, tail-recurse performing the same
    // operations for any child node present. Setting the `parent_id` property at
    // each level will take care of the nesting work for us.
    foreach($tree as $attributes) {
      $node = $this->firstOrNew($this->getSearchAttributes($attributes));

      $data = $this->getDataAttributes($attributes);
      $data[$node->getParentColumnName()] = $parentKey;
      
      $node->fill($data);

      $result = $node->save();

      if ( ! $result ) return false;

      $affectedKeys[] = $node->getKey();

      if ( array_key_exists($this->getChildrenKeyName(), $attributes) ) {
        $children = $attributes[$this->getChildrenKeyName()];

        if ( count($children) > 0 ) {
          $result = $this->mapTreeRecursive($children, $node->getKey(), $affectedKeys);

          if ( ! $result ) return false;
        }
      }
    }

    return true;
  }

  protected function getSearchAttributes($attributes) {
    $searchable = array($this->node->getKeyName());

    return array_only($attributes, $searchable);
  }

  protected function getDataAttributes($attributes) {
    $exceptions = array($this->node->getKeyName(), $this->getChildrenKeyName());

    return array_except($attributes, $exceptions);
  }

  protected function firstOrNew($attributes) {
    $className = get_class($this->node);

    if ( count($attributes) === 0 )
      return new $className;

    return forward_static_call(array($className, 'firstOrNew'), $attributes);
  }

  protected function pruneScope() {
    if ( $this->node->exists )
      return $this->node->descendants();

    return $this->node->newNestedSetQuery();
  }

  protected function deleteUnaffected($keys = array()) {
    return $this->pruneScope()->whereNotIn($this->node->getKeyName(), $keys)->delete();
  }

  protected function wrapInTransaction(Closure $callback) {
    return $this->node->getConnection()->transaction($callback);
  }

}
