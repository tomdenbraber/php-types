<?php


interface Interface1 {
}

class Baz extends Bar {
}

class Foo {
}

class Bar extends Foo implements Interface2 {
}

interface Interface2 extends Interface1 {
}

class Quux implements  IteratorAggregate {}