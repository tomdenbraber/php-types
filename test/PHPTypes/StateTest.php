<?php

namespace PHPTypes;

use PHPCfg\Parser;
use PhpParser\ParserFactory;

class StateTest extends \PHPUnit_Framework_TestCase {
	/** @var  Parser */
	private $parser;

	protected function setUp() {
		$this->parser = new Parser((new ParserFactory())->create(ParserFactory::PREFER_PHP7));
	}

	public function testClassHierarchyConstruction() {
		$filename = __DIR__ . '/assets/stateTest.php';
		$script = $this->parser->parse(file_get_contents($filename), $filename);
		$state = new State([$script]);
		$expectedClassResolves = [
			'interface1' => ['interface1'],
			'interface2' => ['interface2', 'interface1'],
			'foo' => ['foo'],
			'bar' => ['bar', 'foo', 'interface1', 'interface2'],
			'baz' => ['baz', 'bar', 'foo', 'interface1', 'interface2'],
			'quux' => ['quux', 'iteratoraggregate', 'traversable'],
		];
		foreach ($expectedClassResolves as $name => $expected_pnames) {
			$actual_pnames = $state->classResolves[$name];
			sort($expected_pnames);
			sort($actual_pnames);
			$this->assertEquals($expected_pnames, $actual_pnames, "Resolves did not match for `$name`");
		}
		$expectedClassResolvedBy = [
			'interface1' => ['interface1', 'interface2', 'bar', 'baz'],
			'interface2' => ['interface2', 'bar', 'baz'],
			'foo' => ['foo', 'bar', 'baz'],
			'bar' => ['bar', 'baz'],
			'baz' => ['baz'],
			'quux' => ['quux'],
		];
		foreach ($expectedClassResolvedBy as $name => $expected_snames) {
			$actual_snames = $state->classResolvedBy[$name];
			sort($expected_snames);
			sort($actual_snames);
			$this->assertEquals($expected_snames, $actual_snames, "Resolved by did not match for `$name`");
		}
	}
}