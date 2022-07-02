<?php

/**
 * @noinspection PhpPossiblePolymorphicInvocationInspection PHPStorm is unaware that Category::query returns a
 *                                                          Nested Set query and tries to resolve the method to
 *                                                          the normal query builder
 * @noinspection PhpUnhandledExceptionInspection            Tests are supposed to throw exceptions
 */

namespace Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection as BaseCollection;
use Kalnoy\Nestedset\NestedSet;
use Kalnoy\Nestedset\Collection as NSCollection;
use PHPUnit\Framework\TestCase;
use Tests\Models\Category;
use function Safe\sleep;

class NodeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $schema = Capsule::schema();

        $schema->dropIfExists('categories');

        Capsule::connection()->disableQueryLog();

        $schema->create('categories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->softDeletes();
            NestedSet::columns($table);
        });

        Capsule::connection()->enableQueryLog();
    }

    public function setUp(): void
    {
        $data = include __DIR__ . '/Data/categories.php';

        Capsule::table('categories')->insert($data);

        Capsule::connection()->flushQueryLog();

        Category::resetActionsPerformed();

        date_default_timezone_set('America/Denver');
    }

    public function tearDown(): void
    {
        Capsule::table('categories')->truncate();
    }

    public function assertTreeNotBroken(string $table = 'categories'): void
    {
        $checks = [];

        $connection = Capsule::connection();

        $table = $connection->getQueryGrammar()->wrapTable($table);

        // Check if lft and rgt values are ok
        $checks[] = "from $table where _lft >= _rgt or (_rgt - _lft) % 2 = 0";

        // Check if lft and rgt values are unique
        $checks[] = "from $table c1, $table c2 where c1.id <> c2.id and ".
            "(c1._lft=c2._lft or c1._rgt=c2._rgt or c1._lft=c2._rgt or c1._rgt=c2._lft)";

        // Check if parent_id is set correctly
        $checks[] = "from $table c, $table p, $table m where c.parent_id=p.id and m.id <> p.id and m.id <> c.id and ".
             "(c._lft not between p._lft and p._rgt or c._lft between m._lft and m._rgt and m._lft between p._lft and p._rgt)";

        foreach ($checks as $i => $check) {
            $checks[$i] = 'select 1 as error '.$check;
        }

        $sql = 'select max(error) as errors from ('.implode(' union ', $checks).') _';

        $actual = $connection->selectOne($sql);

        self::assertEquals(null, $actual->errors, "The tree structure of $table is broken!");
        $actual = (array)Capsule::connection()->selectOne($sql);

        self::assertEquals(array('errors' => null), $actual, "The tree structure of $table is broken!");
    }

    public function assertNodeReceivesValidValues(Category $node): void
    {
        $lft = $node->getLft();
        $rgt = $node->getRgt();
        $nodeInDb = $this->findCategory($node->name);

        self::assertEquals(
            [ $nodeInDb->getLft(), $nodeInDb->getRgt() ],
            [ $lft, $rgt ],
            'Node is not synced with database after save.'
        );
    }

	/**
	 * @param string $name
	 * @param bool $withTrashed
	 * @return Category
	 */
    public function findCategory(string $name, bool $withTrashed = false): Category
    {
        $q = new Category();

        $q = $withTrashed ? $q->withTrashed() : $q->newQuery();

        return $q->where('name', '=', $name)->first();
    }

    public function testTreeNotBroken(): void
    {
        $this->assertTreeNotBroken();
        self::assertFalse(Category::query()->isBroken());
    }

	/**
	 * @param Category $node
	 * @return array{int, int, int|string|null}
	 */
    public function nodeValues(Category $node): array
    {
        return [$node->_lft, $node->_rgt, $node->parent_id];
    }

    public function testGetsNodeData(): void
    {
        $data = Category::query()->getNodeData(3);

        self::assertEquals([ '_lft' => 3, '_rgt' => 4 ], $data);
    }

    public function testGetsPlainNodeData(): void
    {
        $data = Category::query()->getPlainNodeData(3);

        self::assertEquals([ 3, 4 ], $data);
    }

    public function testReceivesValidValuesWhenAppendedTo(): void
    {
        $node = new Category([ 'name' => 'test' ]);
        $root = Category::query()->root();

        $accepted = array($root->_rgt, $root->_rgt + 1, $root->id);

        $root->appendNode($node);

        self::assertTrue($node->hasMoved());
        self::assertEquals($accepted, $this->nodeValues($node));
        $this->assertTreeNotBroken();
        self::assertFalse($node->isDirty());
        self::assertTrue($node->isDescendantOf($root));
    }

    public function testReceivesValidValuesWhenPrependedTo(): void
    {
        $root = Category::query()->root();
        $node = new Category([ 'name' => 'test' ]);
        $root->prependNode($node);

        self::assertTrue($node->hasMoved());
        self::assertEquals(array($root->_lft + 1, $root->_lft + 2, $root->id), $this->nodeValues($node));
        $this->assertTreeNotBroken();
        self::assertTrue($node->isDescendantOf($root));
        self::assertTrue($root->isAncestorOf($node));
        self::assertTrue($node->isChildOf($root));
    }

    public function testReceivesValidValuesWhenInsertedAfter(): void
    {
        $target = $this->findCategory('apple');
        $node = new Category([ 'name' => 'test' ]);
        $node->afterNode($target)->save();

        self::assertTrue($node->hasMoved());
        self::assertEquals([$target->_rgt + 1, $target->_rgt + 2, $target->parent->id], $this->nodeValues($node));
        $this->assertTreeNotBroken();
        self::assertFalse($node->isDirty());
        self::assertTrue($node->isSiblingOf($target));
    }

    public function testReceivesValidValuesWhenInsertedBefore(): void
    {
        $target = $this->findCategory('apple');
        $node = new Category([ 'name' => 'test' ]);
        $node->beforeNode($target)->save();

        self::assertTrue($node->hasMoved());
        self::assertEquals(array($target->_lft, $target->_lft + 1, $target->parent->id), $this->nodeValues($node));
        $this->assertTreeNotBroken();
    }

    public function testCategoryMovesDown(): void
    {
        $node = $this->findCategory('apple');
        $target = $this->findCategory('mobile');

        $target->appendNode($node);

        self::assertTrue($node->hasMoved());
        $this->assertNodeReceivesValidValues($node);
        $this->assertTreeNotBroken();
    }

    public function testCategoryMovesUp(): void
    {
        $node = $this->findCategory('samsung');
        $target = $this->findCategory('notebooks');

        $target->appendNode($node);

        self::assertTrue($node->hasMoved());
        $this->assertTreeNotBroken();
        $this->assertNodeReceivesValidValues($node);
    }

    public function testFailsToInsertIntoChild(): void
    {
        $this->expectException(\Exception::class);

        $node = $this->findCategory('notebooks');
        $target = $node->children()->first();

        $node->afterNode($target)->save();
    }

    public function testFailsToAppendIntoItself(): void
    {
        $this->expectException(\Exception::class);

        $node = $this->findCategory('notebooks');

        $node->appendToNode($node)->save();
    }

    public function testFailsToPrependIntoItself(): void
    {
        $this->expectException(\Exception::class);

        $node = $this->findCategory('notebooks');

        $node->prependToNode($node)->save();
    }

    public function testWithoutRootWorks(): void
    {
        $result = Category::query()->withoutRoot()->pluck('name');

        self::assertNotEquals('store', $result);
    }

    public function testAncestorsReturnsAncestorsWithoutNodeItself(): void
    {
        $node = $this->findCategory('apple');
        $path = all($node->ancestors()->pluck('name'));

        self::assertEquals(array('store', 'notebooks'), $path);
    }

    public function testGetsAncestorsByStatic(): void
    {
        $path = all(Category::query()->ancestorsOf(3)->pluck('name'));

        self::assertEquals(array('store', 'notebooks'), $path);
    }

    public function testGetsAncestorsDirect(): void
    {
		/** @var Category $category */
		$category = Category::query()->find(8);
        $path = all($category->getAncestors()->pluck('id'));

        self::assertEquals(array(1, 5, 7), $path);
    }

    public function testDescendants(): void
    {
        $node = $this->findCategory('mobile');
        $descendants = all($node->descendants()->pluck('name'));
        $expected = array('nokia', 'samsung', 'galaxy', 'sony', 'lenovo');

        self::assertEquals($expected, $descendants);

        $descendants = all($node->getDescendants()->pluck('name'));

        self::assertEquals(count($descendants), $node->getDescendantCount());
        self::assertEquals($expected, $descendants);

        $descendants = all(Category::query()->descendantsAndSelf(7)->pluck('name'));
        $expected = [ 'samsung', 'galaxy' ];

        self::assertEquals($expected, $descendants);
    }

    public function testWithDepthWorks(): void
    {
        $nodes = all(Category::query()->withDepth()->limit(4)->pluck('depth'));

        self::assertEquals(array(0, 1, 2, 2), $nodes);
    }

    public function testWithDepthWithCustomKeyWorks(): void
    {
        $node = Category::query()->whereIsRoot()->withDepth('level')->first();

        self::assertTrue(isset($node['level']));
    }

    public function testWithDepthWorksAlongWithDefaultKeys(): void
    {
        $node = Category::query()->withDepth()->first();

        self::assertTrue(isset($node->name));
    }

    public function testParentIdAttributeAccessorAppendsNode(): void
    {
        $node = new Category(['name' => 'lg', 'parent_id' => 5]);
        $node->save();

        self::assertEquals(5, $node->parent_id);
        self::assertEquals(5, $node->getParentId());

        $node->parent_id = null;
        $node->save();

        $node->refreshNode();

        self::assertEquals(null, $node->parent_id);
        self::assertTrue($node->isRoot());
    }

    public function testFailsToSaveNodeUntilNotInserted(): void
    {
        $this->expectException(\Exception::class);

        $node = new Category;
        $node->save();
    }

    public function testNodeIsDeletedWithDescendants(): void
    {
        $node = $this->findCategory('mobile');
        $node->forceDelete();

        $this->assertTreeNotBroken();

        $nodes = Category::query()->whereIn('id', array(5, 6, 7, 8, 9))->count();
        self::assertEquals(0, $nodes);

        $root = Category::query()->root();
        self::assertEquals(8, $root->getRgt());
    }

	public function testNodeIsSoftDeleted(): void
    {
        $root = Category::query()->root();

        $samsung = $this->findCategory('samsung');
        $samsung->delete();

        $this->assertTreeNotBroken();

        self::assertNull($this->findCategory('galaxy'));

        sleep(1);

        $node = $this->findCategory('mobile');
        $node->delete();

        $nodes = Category::query()->whereIn('id', array(5, 6, 7, 8, 9))->count();
        self::assertEquals(0, $nodes);

        $originalRgt = $root->getRgt();
        $root->refreshNode();

        self::assertEquals($originalRgt, $root->getRgt());

        $node = $this->findCategory('mobile', true);

        $node->restore();

        self::assertNull($this->findCategory('samsung'));
        self::assertNotNull($this->findCategory('nokia'));
    }

    public function testSoftDeletedNodeisDeletedWhenParentIsDeleted(): void
    {
        $this->findCategory('samsung')->delete();

        $this->findCategory('mobile')->forceDelete();

        $this->assertTreeNotBroken();

        self::assertNull($this->findCategory('samsung', true));
        self::assertNull($this->findCategory('sony'));
    }

    public function testFailsToSaveNodeUntilParentIsSaved(): void
    {
        $this->expectException(\Exception::class);

        $node = new Category(array('title' => 'Node'));
        $parent = new Category(array('title' => 'Parent'));

        $node->appendToNode($parent)->save();
    }

    public function testSiblings(): void
    {
        $node = $this->findCategory('samsung');
        $siblings = all($node->siblings()->pluck('id'));
        $next = all($node->nextSiblings()->pluck('id'));
        $prev = all($node->prevSiblings()->pluck('id'));

        self::assertEquals(array(6, 9, 10), $siblings);
        self::assertEquals(array(9, 10), $next);
        self::assertEquals(array(6), $prev);

        $siblings = all($node->getSiblings()->pluck('id'));
        $next = all($node->getNextSiblings()->pluck('id'));
        $prev = all($node->getPrevSiblings()->pluck('id'));

        self::assertEquals(array(6, 9, 10), $siblings);
        self::assertEquals(array(9, 10), $next);
        self::assertEquals(array(6), $prev);

        $next = $node->getNextSibling();
        $prev = $node->getPrevSibling();

        self::assertEquals(9, $next->id);
        self::assertEquals(6, $prev->id);
    }

    public function testFetchesReversed(): void
    {
        $node = $this->findCategory('sony');
        $siblings = $node->prevSiblings()->reversed()->value('id');

        self::assertEquals(7, $siblings);
    }

    public function testToTreeBuildsWithDefaultOrder(): void
    {
        $tree = Category::query()->whereBetween('_lft', [8, 17])->defaultOrder()->get()->toTree();

        self::assertCount(1, $tree);

        $root = $tree->first();
        self::assertEquals('mobile', $root->name);
        self::assertCount(4, $root->children);
    }

    public function testToTreeBuildsWithCustomOrder(): void
    {
        $tree = Category::query()
	        ->whereBetween('_lft', [8, 17])
            ->orderBy('title')
            ->get()
            ->toTree();

        self::assertCount(1, $tree);

        $root = $tree->first();
        self::assertEquals('mobile', $root->name);
        self::assertCount(4, $root->children);
        self::assertEquals($root, $root->children->first()->parent);
    }

    public function testToTreeWithSpecifiedRoot(): void
    {
        $node = $this->findCategory('mobile');
        $nodes = Category::query()->whereBetween('_lft', [8, 17])->get();

        $tree1 = (new NSCollection($nodes))->toTree(5);
        $tree2 = (new NSCollection($nodes))->toTree($node);

        self::assertEquals(4, $tree1->count());
        self::assertEquals(4, $tree2->count());
    }

    public function testToTreeBuildsWithDefaultOrderAndMultipleRootNodes(): void
    {
        $tree = Category::query()->withoutRoot()->get()->toTree();

        self::assertCount(2, $tree);
    }

    public function testToTreeBuildsWithRootItemIdProvided(): void
    {
        $tree = Category::query()->whereBetween('_lft', array(8, 17))->get()->toTree(5);

        self::assertCount(4, $tree);

        $root = $tree[1];
        self::assertEquals('samsung', $root->name);
        self::assertCount(1, $root->children);
    }

    public function testRetrievesNextNode(): void
    {
        $node = $this->findCategory('apple');
		/** @var Category $next */
        $next = $node->nextNodes()->first();

        self::assertEquals('lenovo', $next->name);
    }

    public function testRetrievesPrevNode(): void
    {
        $node = $this->findCategory('apple');
        $next = $node->getPrevNode();

        self::assertEquals('notebooks', $next->name);
    }

    public function testMultipleAppendageWorks(): void
    {
        $parent = $this->findCategory('mobile');

        $child = new Category([ 'name' => 'test' ]);

        $parent->appendNode($child);

        $child->appendNode(new Category([ 'name' => 'sub' ]));

        $parent->appendNode(new Category([ 'name' => 'test2' ]));

        $this->assertTreeNotBroken();
    }

    public function testDefaultCategoryIsSavedAsRoot(): void
    {
        $node = new Category([ 'name' => 'test' ]);
        $node->save();

        self::assertEquals(23, $node->_lft);
        $this->assertTreeNotBroken();

        self::assertTrue($node->isRoot());
    }

    public function testExistingCategorySavedAsRoot(): void
    {
        $node = $this->findCategory('apple');
        $node->saveAsRoot();

        $this->assertTreeNotBroken();
        self::assertTrue($node->isRoot());
    }

    public function testNodeMovesDownSeveralPositions(): void
    {
        $node = $this->findCategory('nokia');

        self::assertTrue($node->down(2));

        self::assertEquals(15, $node->_lft);
    }

    public function testNodeMovesUpSeveralPositions(): void
    {
        $node = $this->findCategory('sony');

        self::assertTrue($node->up(2));

        self::assertEquals(9, $node->_lft);
    }

    public function testCountsTreeErrors(): void
    {
        $errors = Category::query()->countErrors();

        self::assertEquals([ 'oddness' => 0,
                              'duplicates' => 0,
                              'wrong_parent' => 0,
                              'missing_parent' => 0 ], $errors);

        Category::query()->where('id', '=', 5)->update([ '_lft' => 14 ]);
        Category::query()->where('id', '=', 8)->update([ 'parent_id' => 2 ]);
        Category::query()->where('id', '=', 11)->update([ '_lft' => 20 ]);
        Category::query()->where('id', '=', 4)->update([ 'parent_id' => 24 ]);

        $errors = Category::query()->countErrors();

        self::assertEquals(1, $errors['oddness']);
        self::assertEquals(2, $errors['duplicates']);
        self::assertEquals(1, $errors['missing_parent']);
    }

    public function testCreatesNode(): void
    {
        $node = Category::create([ 'name' => 'test' ]);

        self::assertEquals(23, $node->getLft());
    }

    public function testCreatesViaRelationship(): void
    {
        $node = $this->findCategory('apple');

        $node->children()->create([ 'name' => 'test' ]);

        $this->assertTreeNotBroken();
    }

    public function testCreatesTree(): void
    {
        $node = Category::create(
        [
            'name' => 'test',
            'children' =>
            [
                [ 'name' => 'test2' ],
                [ 'name' => 'test3' ],
            ],
        ]);

        $this->assertTreeNotBroken();

        self::assertTrue(isset($node->children));

        $node = $this->findCategory('test');

        self::assertCount(2, $node->children);
        self::assertEquals('test2', $node->children[0]->name);
    }

    public function testDescendantsOfNonExistingNode(): void
    {
        $node = new Category;

        self::assertTrue($node->getDescendants()->isEmpty());
    }

    public function testWhereDescendantsOf(): void
    {
        $this->expectException(ModelNotFoundException::class);

        Category::query()->whereDescendantOf(124)->get();
    }

    public function testAncestorsByNode(): void
    {
        $category = $this->findCategory('apple');
        $ancestors = all(Category::query()->whereAncestorOf($category)->pluck('id'));

        self::assertEquals([ 1, 2 ], $ancestors);
    }

    public function testDescendantsByNode(): void
    {
        $category = $this->findCategory('notebooks');
        $res = all(Category::query()->whereDescendantOf($category)->pluck('id'));

        self::assertEquals([ 3, 4 ], $res);
    }

    public function testMultipleDeletionsDoNotBrakeTree(): void
    {
        $category = $this->findCategory('mobile');

        foreach ($category->children()->take(2)->get() as $child)
        {
            $child->forceDelete();
        }

        $this->assertTreeNotBroken();
    }

    public function testTreeIsFixed(): void
    {
        Category::query()->where('id', '=', 5)->update([ '_lft' => 14 ]);
        Category::query()->where('id', '=', 8)->update([ 'parent_id' => 2 ]);
        Category::query()->where('id', '=', 11)->update([ '_lft' => 20 ]);
        Category::query()->where('id', '=', 2)->update([ 'parent_id' => 24 ]);

        $fixed = Category::query()->fixTree();

        self::assertTrue($fixed > 0);
        $this->assertTreeNotBroken();

		/** @var Category $node */
        $node = Category::query()->find(8);

        self::assertEquals(2, $node->getParentId());

	    /** @var Category $node */
        $node = Category::query()->find(2);

        self::assertEquals(null, $node->getParentId());
    }

    public function testSubtreeIsFixed(): void
    {
        Category::query()->where('id', '=', 8)->update([ '_lft' => 11 ]);

        $fixed = Category::query()->fixSubtree(Category::query()->find(5));
        self::assertEquals(1, $fixed);
        $this->assertTreeNotBroken();
		/** @var Category $category */
		$category = Category::query()->find(8);
        self::assertEquals(12, $category->getLft());
    }

    public function testParentIdDirtiness(): void
    {
        $node = $this->findCategory('apple');
        $node->parent_id = 5;

        self::assertTrue($node->isDirty('parent_id'));

        $node = $this->findCategory('apple');
        $node->parent_id = null;

        self::assertTrue($node->isDirty('parent_id'));
    }

    public function testIsDirtyMovement(): void
    {
        $node = $this->findCategory('apple');
        $otherNode = $this->findCategory('samsung');

        self::assertFalse($node->isDirty());

        $node->afterNode($otherNode);

        self::assertTrue($node->isDirty());

        $node = $this->findCategory('apple');
        $otherNode = $this->findCategory('samsung');

        self::assertFalse($node->isDirty());

        $node->appendToNode($otherNode);

        self::assertTrue($node->isDirty());
    }

    public function testRootNodesMoving(): void
    {
        $node = $this->findCategory('store');
        $node->down();

        self::assertEquals(3, $node->getLft());
    }

    public function testDescendantsRelation(): void
    {
        $node = $this->findCategory('notebooks');
        $result = $node->descendants;

        self::assertEquals(2, $result->count());
        self::assertEquals('apple', $result->first()->name);
    }

    public function testDescendantsEagerlyLoaded(): void
    {
        $nodes = Category::query()->whereIn('id', [ 2, 5 ])->get();

        $nodes->load('descendants');

        self::assertEquals(2, $nodes->count());
        self::assertTrue($nodes->first()->relationLoaded('descendants'));
    }

    public function testDescendantsRelationQuery(): void
    {
        $nodes = Category::query()->has('descendants')->whereIn('id', [ 2, 3 ])->get();

        self::assertEquals(1, $nodes->count());
        self::assertEquals(2, $nodes->first()->getKey());

        $nodes = Category::query()->has('descendants', '>', 2)->get();

        self::assertEquals(2, $nodes->count());
        self::assertEquals(1, $nodes[0]->getKey());
        self::assertEquals(5, $nodes[1]->getKey());
    }

    public function testParentRelationQuery(): void
    {
        $nodes = Category::query()->has('parent')->whereIn('id', [ 1, 2 ]);

        self::assertEquals(1, $nodes->count());
        self::assertEquals(2, $nodes->first()->getKey());
    }

    public function testRebuildTree(): void
    {
        $fixed = Category::query()->rebuildTree([
            [
                'id' => 1,
                'children' => [
                    [ 'id' => 10 ],
                    [ 'id' => 3, 'name' => 'apple v2', 'children' => [ [ 'name' => 'new node' ] ] ],
                    [ 'id' => 2 ],

                ]
            ]
        ]);

        self::assertTrue($fixed > 0);
        $this->assertTreeNotBroken();

		/** @var Category $node */
        $node = Category::query()->find(3);

        self::assertEquals(1, $node->getParentId());
        self::assertEquals('apple v2', $node->name);
        self::assertEquals(4, $node->getLft());

        $node = $this->findCategory('new node');

        self::assertNotNull($node);
        self::assertEquals(3, $node->getParentId());
    }

    public function testRebuildSubtree(): void
    {
        $fixed = Category::query()->rebuildSubtree(Category::query()->find(7), [
            [ 'name' => 'new node' ],
            [ 'id' => '8' ],
        ]);

        self::assertTrue($fixed > 0);
        $this->assertTreeNotBroken();

        $node = $this->findCategory('new node');

        self::assertNotNull($node);
        self::assertEquals(12, $node->getLft());
    }

    public function testRebuildTreeWithDeletion(): void
    {
        Category::query()->rebuildTree([ [ 'name' => 'all deleted' ] ], true);

        $this->assertTreeNotBroken();

        $nodes = Category::query()->get();

        self::assertEquals(1, $nodes->count());
        self::assertEquals('all deleted', $nodes->first()->name);

        $nodes = Category::withTrashed()->get();

        self::assertTrue($nodes->count() > 1);
    }

    public function testRebuildFailsWithInvalidPK(): void
    {
        $this->expectException(ModelNotFoundException::class);

        Category::query()->rebuildTree([ [ 'id' => 24 ] ]);
    }

    public function testFlatTree(): void
    {
        $node = $this->findCategory('mobile');
        $tree = $node->descendants()->orderBy('name')->get()->toFlatTree();

        self::assertCount(5, $tree);
        self::assertEquals('samsung', $tree[2]->name);
        self::assertEquals('galaxy', $tree[3]->name);
    }

    public function testWhereIsLeaf(): void
    {
        $categories = Category::query()->leaves();

        self::assertEquals(7, $categories->count());
        self::assertEquals('apple', $categories->first()->name);
        self::assertTrue($categories->first()->isLeaf());

        $category = Category::query()->whereIsRoot()->first();

        self::assertFalse($category->isLeaf());
    }

    public function testEagerLoadAncestors(): void
    {
        $queryLogCount = count(Capsule::connection()->getQueryLog());
        $categories = Category::query()->with('ancestors')->orderBy('name')->get();

        self::assertCount($queryLogCount + 2, Capsule::connection()->getQueryLog());

        $expectedShape = [
            'apple (3)}' => 'store (1) > notebooks (2)',
            'galaxy (8)}' => 'store (1) > mobile (5) > samsung (7)',
            'lenovo (4)}' => 'store (1) > notebooks (2)',
            'lenovo (10)}' => 'store (1) > mobile (5)',
            'mobile (5)}' => 'store (1)',
            'nokia (6)}' => 'store (1) > mobile (5)',
            'notebooks (2)}' => 'store (1)',
            'samsung (7)}' => 'store (1) > mobile (5)',
            'sony (9)}' => 'store (1) > mobile (5)',
            'store (1)}' => '',
            'store_2 (11)}' => ''
        ];

        $output = [];

        foreach ($categories as $category) {
            $output["{$category->name} ({$category->id})}"] =
	            $category->ancestors->count() !== 0 ?
		            implode(' > ', $category->ancestors->map(function ($cat) { return "{$cat->name} ({$cat->id})"; })->toArray()) :
		            '';
        }

        self::assertEquals($expectedShape, $output);
    }

    public function testLazyLoadAncestors(): void
    {
        $queryLogCount = count(Capsule::connection()->getQueryLog());
        $categories = Category::query()->orderBy('name')->get();

        self::assertCount($queryLogCount + 1, Capsule::connection()->getQueryLog());

        $expectedShape = [
            'apple (3)}' => 'store (1) > notebooks (2)',
            'galaxy (8)}' => 'store (1) > mobile (5) > samsung (7)',
            'lenovo (4)}' => 'store (1) > notebooks (2)',
            'lenovo (10)}' => 'store (1) > mobile (5)',
            'mobile (5)}' => 'store (1)',
            'nokia (6)}' => 'store (1) > mobile (5)',
            'notebooks (2)}' => 'store (1)',
            'samsung (7)}' => 'store (1) > mobile (5)',
            'sony (9)}' => 'store (1) > mobile (5)',
            'store (1)}' => '',
            'store_2 (11)}' => ''
        ];

        $output = [];

        foreach ($categories as $category) {
            $output["{$category->name} ({$category->id})}"] =
	            $category->ancestors->count() !== 0 ?
                    implode(' > ', $category->ancestors->map(function ($cat) { return "{$cat->name} ({$cat->id})"; })->toArray()) :
                    '';
        }

        // assert that there is number of original query + 1 + number of rows to fulfill the relation
        self::assertCount($queryLogCount + 12, Capsule::connection()->getQueryLog());

        self::assertEquals($expectedShape, $output);
    }

    public function testWhereHasCountQueryForAncestors(): void
    {
        $categories = all(Category::query()->has('ancestors', '>', 2)->pluck('name'));

        self::assertEquals([ 'galaxy' ], $categories);

        $categories = all(Category::query()->whereHas('ancestors', function ($query) {
            $query->where('id', 5);
        })->pluck('name'));

        self::assertEquals([ 'nokia', 'samsung', 'galaxy', 'sony', 'lenovo' ], $categories);
    }

    public function testReplication(): void
    {
        $category = $this->findCategory('nokia');
        $category = $category->replicate();
        $category->save();
        $category->refreshNode();

        self::assertNull($category->getParentId());

        $category = $this->findCategory('nokia');
        $category = $category->replicate();
        $category->parent_id = 1;
        $category->save();

        $category->refreshNode();

        self::assertEquals(1, $category->getParentId());
    }

}

function all(array|BaseCollection $items): array
{
    return is_array($items) ? $items : $items->all();
}
