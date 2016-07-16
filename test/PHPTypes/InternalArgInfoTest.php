<?php

namespace PHPTypes;

class InternalArgInfoTest extends \PHPUnit_Framework_TestCase {
	/** @var  InternalArgInfo */
	private $internalArgInfo;

	protected function setUp() {
		$this->internalArgInfo = new InternalArgInfo();
	}

	public function testResolvesClassHierarchy() {
		$this->assertArrayHasKey('exception', $this->internalArgInfo->methods);
		$this->assertArrayHasKey('exception', $this->internalArgInfo->classResolvedBy);
		$this->assertContains('runtimeexception', $this->internalArgInfo->classResolvedBy['exception']);
		$this->assertArrayHasKey('exception', $this->internalArgInfo->classResolves);
		$this->assertContains('exception', $this->internalArgInfo->classResolves['runtimeexception']);
	}
}