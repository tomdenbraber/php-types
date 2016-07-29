<?php

/*
 * This file is part of PHP-Types, a type reconstruction lib for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPTypes;

class TypeTest extends \PHPUnit_Framework_TestCase {
    
    public static function provideTestDecl() {
        return [
            ["int", new Type(Type::TYPE_LONG)],
            ["int[]", new Type(Type::TYPE_ARRAY, [new Type(Type::TYPE_LONG)])],
            ["int|float", new Type(Type::TYPE_UNION, [new Type(Type::TYPE_LONG), new Type(Type::TYPE_DOUBLE)])],
            ["Traversable|array", new Type(Type::TYPE_UNION, [new Type(Type::TYPE_OBJECT, [], "Traversable"), new Type(Type::TYPE_ARRAY)])],
            ["Traversable&array", new Type(Type::TYPE_INTERSECTION, [new Type(Type::TYPE_OBJECT, [], "Traversable"), new Type(Type::TYPE_ARRAY)])],
            ["Traversable|array|int", new Type(Type::TYPE_UNION, [new Type(Type::TYPE_OBJECT, [], "Traversable"), new Type(Type::TYPE_ARRAY), new Type(Type::TYPE_LONG)])],
            ["Traversable|(array&int)", new Type(Type::TYPE_UNION, [new Type(Type::TYPE_OBJECT, [], "Traversable"), new Type(Type::TYPE_INTERSECTION, [new Type(Type::TYPE_ARRAY), new Type(Type::TYPE_LONG)])])],
            ["Traversable|(int[]&int)", new Type(Type::TYPE_UNION, [new Type(Type::TYPE_OBJECT, [], "Traversable"), new Type(Type::TYPE_INTERSECTION, [new Type(Type::TYPE_ARRAY, [new Type(Type::TYPE_LONG)]), new Type(Type::TYPE_LONG)])])],
            ["Traversable|((bool|float)&int)", new Type(Type::TYPE_UNION, [new Type(Type::TYPE_OBJECT, [], "Traversable"), new Type(Type::TYPE_INTERSECTION, [new Type(Type::TYPE_UNION, [new Type(Type::TYPE_BOOLEAN), new Type(Type::TYPE_DOUBLE)]), new Type(Type::TYPE_LONG)])])],
        ];
    }

    /**
     * @dataProvider provideTestDecl
     */
    public function testDecl($decl, $result) {
        $type = Type::fromDecl($decl);
        $this->assertEquals($result, $type);
        $this->assertEquals($decl, (string) $type);
    }

    public function provideTestUnion() {
    	return [
		    [[new Type(Type::TYPE_LONG), new Type(Type::TYPE_STRING)], new Type(Type::TYPE_UNION, [new Type(Type::TYPE_LONG), new Type(Type::TYPE_STRING)])],
		    [[new Type(Type::TYPE_LONG), new Type(Type::TYPE_LONG)], new Type(Type::TYPE_LONG)],
	    ];
    }

    /**
     * @dataProvider provideTestUnion
     */
    public function testUnion($types, $expected) {
	    $type = Type::union($types);
	    $this->assertEquals($expected, $type);
    }

	public function provideTestIntersection() {
		return [
			[[new Type(Type::TYPE_LONG), new Type(Type::TYPE_STRING)], new Type(Type::TYPE_INTERSECTION, [new Type(Type::TYPE_LONG), new Type(Type::TYPE_STRING)])],
			[[new Type(Type::TYPE_LONG), new Type(Type::TYPE_LONG)], new Type(Type::TYPE_LONG)],
		];
	}

    /**
     * @dataProvider provideTestIntersection
     */
    public function testIntersection($types, $expected) {
	    $type = Type::intersection($types);
	    $this->assertEquals($expected, $type);
    }
}