<?php

/*
 * This file is part of PHP-Types, a type reconstruction lib for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPTypes;

use PHPCfg\Block;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCfg\Traverser;
use PHPCfg\Visitor;
use SplObjectStorage;

class State {
    /** @var InternalArgInfo  */
    public $internalTypeInfo;
    /** @var TypeResolver  */
    public $resolver;

    /** @var Block[] */
    public $scripts = [];

    /** @var Op\Stmt\Class_[][] */
    public $classMap = [];

    /** @var SplObjectStorage */
    public $variables;

    /** @var Op\Terminal\Const_[] */
    public $constants;
    /** @var Op\Stmt\Trait_[] */
    public $traits;
    /** @var Op\Stmt\Class_[] */
    public $classes;
    /** @var Op\Stmt\Interface_[] */
    public $interfaces;
    /** @var Op\Stmt\ClassMethod[] */
    public $methods;
    /** @var Op\Stmt\ClassMethod[][][] */
    public $methodLookup;           // Method definitions indexed by class name and method name - due to conditional inclusion there could be multiple definitions for the same method name
    /** @var Op\Stmt\Function_[] */
    public $functions;
    /** @var Op\Stmt\Function_[][] */
    public $functionLookup;

	/** @var array|string[][] */
	public $classExtends = [];      // Index of all parent classes of a class - due to conditional inclusion there could be multiple parents for the same class name
	/** @var array|string[][]  */
    public $classResolves = [];     // Index of all superclasses of a class
	/** @var array|string[][]  */
    public $classResolvedBy = [];   // Index of all subclasses of a class

    /** @var Op\Expr\FuncCall[] */
    public $funcCalls = [];
    /** @var Op\Expr\NsFuncCall[] */
    public $nsFuncCalls = [];
    /** @var Op\Expr\MethodCall[] */
    public $methodCalls = [];
    /** @var Op\Expr\StaticCall[] */
    public $staticCalls = [];
    /** @var Op\Expr\New_[] */
    public $newCalls = [];

    /**
     * State constructor.
     * @param Script[] $scripts
     */
    public function __construct(array $scripts) {
        $this->scripts = $scripts;
        $this->resolver = new TypeResolver($this);
        $this->internalTypeInfo = new InternalArgInfo;
	    foreach ($this->internalTypeInfo->classExtends as $classname => $pclassname) {
		    $this->classExtends[$classname][$pclassname] = $pclassname;
	    }
	    $this->classExtends = $this->internalTypeInfo->classExtends;
	    $this->classResolves = $this->internalTypeInfo->classResolves;
	    $this->classResolvedBy = $this->internalTypeInfo->classResolvedBy;
        $this->load();
    }


    private function load() {
        $traverser = new Traverser;
        $declarations = new Visitor\DeclarationFinder;
        $calls = new Visitor\CallFinder;
        $variables = new Visitor\VariableFinder;
        $traverser->addVisitor($declarations);
        $traverser->addVisitor($calls);
        $traverser->addVisitor($variables);

        foreach ($this->scripts as $script) {
            $traverser->traverse($script);
        }

        $this->variables = $variables->getVariables();
        $this->constants = $declarations->getConstants();
        $this->traits = $declarations->getTraits();
        $this->classes = $declarations->getClasses();
        $this->interfaces = $declarations->getInterfaces();
        $this->methods = $declarations->getMethods();
	    $this->methodLookup = $this->buildMethodLookup($declarations->getMethods());
        $this->functions = $declarations->getFunctions();
        $this->functionLookup = $this->buildFunctionLookup($declarations->getFunctions());
        $this->funcCalls = $calls->getFuncCalls();
        $this->nsFuncCalls = $calls->getNsFuncCalls();
        $this->methodCalls = $calls->getMethodCalls();
        $this->staticCalls = $calls->getStaticCalls();
        $this->newCalls = $calls->getNewCalls();
        $this->computeTypeMatrix();
    }

    /**
     * @param Op\Stmt\Function_[] $functions
     * @return Op\Stmt\Function_[][]
     */
    private function buildFunctionLookup(array $functions) {
        $lookup = [];
        foreach ($functions as $function) {
            $name = strtolower($function->func->name);
            $lookup[$name][] = $function;
        }
        return $lookup;
    }

	/**
	 * @param Op\Stmt\ClassMethod[] $methods
	 * @return Op\Stmt\ClassMethod[][]
	 */
    private function buildMethodLookup(array $methods) {
    	$lookup = [];
	    foreach ($methods as $method) {
	    	$classname = strtolower($method->getFunc()->class->value);
	    	$name = strtolower($method->func->name);
		    $lookup[$classname][$name][] = $method;
	    }
	    return $lookup;
    }

    private function computeTypeMatrix() {
	    foreach ($this->interfaces as $interface) {
	    	$name = strtolower($interface->name->value);
		    $this->classResolves[$name][$name] = $name;
		    $this->classResolvedBy[$name][$name] = $name;
		    if ($interface->extends !== null) {
		    	foreach ($interface->extends as $extends) {
				    assert($extends instanceof Operand\Literal);
				    $pname = strtolower($extends->value);
				    $this->classResolves[$name][$pname] = $pname;
				    $this->classResolvedBy[$pname][$name] = $name;
			    }
		    }
	    }
	    foreach ($this->classes as $class) {
	    	$name = strtolower($class->name->value);
		    $this->classResolves[$name][$name] = $name;
		    $this->classResolvedBy[$name][$name] = $name;
		    if ($class->extends !== null) {
			    assert($class->extends instanceof Operand\Literal);
			    $pname = strtolower($class->extends->value);
			    $this->classExtends[$name][$pname] = $pname;
			    $this->classResolves[$name][$pname] = $pname;
			    $this->classResolvedBy[$pname][$name] = $name;
		    }
		    foreach ($class->implements as $implements) {
			    assert($implements instanceof Operand\Literal);
			    $iname = strtolower($implements->value);
			    $this->classResolves[$name][$iname] = $iname;
			    $this->classResolvedBy[$iname][$name] = $name;
		    }
	    }

	    // compute transitive closure
	    $all_classes = array_keys($this->classResolves);
	    $queue = array_combine($all_classes, $all_classes);
	    while (!empty($queue)) {
	    	$name = array_shift($queue);
		    foreach ($this->classResolves[$name] as $pname) {
		    	foreach ($this->classResolves[$pname] as $ppname) {
					if (!isset($this->classResolves[$name][$ppname])) {
						$this->classResolves[$name][$ppname] = $ppname;
						$this->classResolvedBy[$ppname][$name] = $name;
						$queue[$pname] = $pname;  // propagate up
					}
			    }
		    }
		    foreach ($this->classResolvedBy[$name] as $sname) {
		    	foreach ($this->classResolvedBy[$sname] as $ssname) {
				    if (!isset($this->classResolvedBy[$name][$ssname])) {
					    $this->classResolvedBy[$name][$ssname] = $ssname;
					    $this->classResolves[$ssname][$name] = $name;
					    $queue[$sname] = $sname;  // propagate down
				    }
			    }
		    }
	    }
    }
}