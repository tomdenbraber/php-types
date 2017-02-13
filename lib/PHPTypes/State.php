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
    /** Op\Stmt\Trait_[][] */
    public $traitLookup = [];
    /** @var Op\Stmt\Class_[] */
    public $classes;
	/** @var Op\Stmt\Class_[][] */
	public $classLookup = [];
    /** @var Op\Stmt\Interface_[] */
    public $interfaces;
	/** Op\Stmt\Interface_[][] */
	public $interfaceLookup = [];
    /** @var Op\Stmt\ClassMethod[] */
    public $methods;
    /** @var \SplObjectStorage|Op\Stmt\ClassMethod[][][] */
    public $methodLookup;               // Method definitions indexed by class name and method name - due to conditional inclusion there could be multiple definitions for the same method name
	/** @var \SplObjectStorage|Op\Stmt\Property[][][] */
	public $propertyLookup;             // Property definitions indexed by class name and property name - due to conditional inclusion there could be multiple definitions for the same property name
	/** @var \SplObjectStorage|Op\Terminal\Const_[][][] */
	public $classConstantLookup;        // Class constant definitions indexed by class name and constant name - due to conditional inclusion there could be multiple definitions for the same class constant name
    /** @var Op\Stmt\Function_[] */
    public $functions;
    /** @var Op\Stmt\Function_[][] */
    public $functionLookup;

	/** @var array|string[][]  */
    public $classResolves = [];         // Index of all superclasses of a class
	/** @var array|string[][]  */
    public $classResolvedBy = [];       // Index of all subclasses of a class

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
	    $this->methodLookup = new SplObjectStorage();
	    $this->propertyLookup = new SplObjectStorage();
	    $this->classConstantLookup = new SplObjectStorage();
	    $this->classResolves = $this->internalTypeInfo->classResolves;
	    $this->classResolvedBy = $this->internalTypeInfo->classResolvedBy;
        $this->load();
    }


    private function load() {
        $traverser = new Traverser;
        $declarations = new Visitor\DeclarationFinder;
        $calls = new Visitor\CallFinder;
        $variables = new Visitor\VariableFinder;
        $trait_resolver = new Visitor\TraitToClassLinker;
        $traverser->addVisitor($declarations);
        $traverser->addVisitor($calls);
        $traverser->addVisitor($variables);
        $traverser->addVisitor($trait_resolver);

        foreach ($this->scripts as $script) {
            $traverser->traverse($script);
        }

        $this->variables = $variables->getVariables();
        $this->constants = $declarations->getConstants();
        $this->traits = $declarations->getTraits();
        $this->classes = $declarations->getClasses();
        $this->interfaces = $declarations->getInterfaces();
        $this->methods = $declarations->getMethods();
        $this->functions = $declarations->getFunctions();

        $this->buildFunctionLookup($this->functions);
	    $this->buildClassLookups($this->classes);
	    $this->buildTraitLookups($this->traits);
	    $this->buildInterfaceLookups($this->interfaces);

        $this->funcCalls = $calls->getFuncCalls();
        $this->nsFuncCalls = $calls->getNsFuncCalls();
        $this->methodCalls = $calls->getMethodCalls();
        $this->staticCalls = $calls->getStaticCalls();
        $this->newCalls = $calls->getNewCalls();

        $this->computeTypeMatrix();
    }

    /**
     * @param Op\Stmt\Function_[] $functions
     */
    private function buildFunctionLookup(array $functions) {
        foreach ($functions as $function) {
            $name = strtolower($function->func->name);
            $this->functionLookup[$name][] = $function;
        }
    }

	/**
	 * @param Op\Stmt\Class_[] $classes
	 */
    private function buildClassLookups(array $classes) {
	    foreach ($classes as $class) {
	    	$methods = [];
		    $properties = [];
		    $constants = [];
		    foreach ($class->stmts->children as $op) {
		    	if ($op instanceof Op\Stmt\ClassMethod) {
		    		$methods[strtolower($op->getFunc()->name)][] = $op;
			    } else if ($op instanceof Op\Stmt\Property) {
					$properties[strtolower($op->name->value)][] = $op;
			    } else if ($op instanceof Op\Terminal\Const_) {
				    $constants[strtolower($op->name->value)][] = $op;
			    }
		    }
		    $this->classLookup[strtolower($class->name->value)][] = $class;
		    $this->methodLookup[$class] = $methods;
		    $this->propertyLookup[$class] = $properties;
		    $this->classConstantLookup[$class] = $constants;
	    }
    }

	/**
	 * @param Op\Stmt\Trait_[] $traits
	 */
	private function buildTraitLookups(array $traits) {
		foreach ($traits as $trait) {
			$methods = [];
			$properties = [];
			$constants = [];
			foreach ($trait->stmts->children as $op) {
				if ($op instanceof Op\Stmt\ClassMethod) {
					$methods[strtolower($op->getFunc()->name)][] = $op;
				} else if ($op instanceof Op\Stmt\Property) {
					$properties[strtolower($op->name->value)][] = $op;
				} else if ($op instanceof Op\Terminal\Const_) {
					$constants[strtolower($op->name->value)][] = $op;
				}
			}
			$this->traitLookup[strtolower($trait->name->value)][] = $trait;
			$this->methodLookup[$trait] = $methods;
			$this->propertyLookup[$trait] = $properties;
			$this->classConstantLookup[$trait] = $constants;
		}
	}

	/**
	 * @param Op\Stmt\Interface_[] $interfaces
	 */
	private function buildInterfaceLookups(array $interfaces) {
		foreach ($interfaces as $interface) {
			$methods = [];
			$constants = [];
			foreach ($interface->stmts->children as $op) {
				if ($op instanceof Op\Stmt\ClassMethod) {
					$methods[strtolower($op->getFunc()->name)][] = $op;
				} else if ($op instanceof Op\Terminal\Const_) {
					$constants[strtolower($op->name->value)][] = $op;
				}
			}
			$this->interfaceLookup[strtolower($interface->name->value)][] = $interface;
			$this->methodLookup[$interface] = $methods;
			$this->classConstantLookup[$interface] = $constants;
		}
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
	    foreach ($this->traits as $trait) {
	    	$name = strtolower($trait->name->value);
	    	$this->classResolves[$name][$name] = $name;
	    	$this->classResolvedBy[$name][$name] = $name;
	    }

	    foreach ($this->classes as $class) {
	    	$name = strtolower($class->name->value);
		    $this->classResolves[$name][$name] = $name;
		    $this->classResolvedBy[$name][$name] = $name;
		    if ($class->extends !== null) {
			    assert($class->extends instanceof Operand\Literal);
			    $pname = strtolower($class->extends->value);
			    $this->classResolves[$name][$pname] = $pname;
			    $this->classResolvedBy[$pname][$name] = $name;
		    }
		    foreach ($class->implements as $implements) {
			    assert($implements instanceof Operand\Literal);
			    $iname = strtolower($implements->value);
			    $this->classResolves[$name][$iname] = $iname;
			    $this->classResolvedBy[$iname][$name] = $name;
		    }
		    foreach ($class->uses as $uses) {
		    	assert($uses instanceof Operand\Literal);
		    	$tname = strtolower($uses->value);
		    	$this->classResolves[$name][$tname] = $tname;
		    	$this->classResolvedBy[$tname][$name] = $name;
		    }
	    }

	    // compute transitive closure
	    $all_classes = array_keys($this->classResolves);
	    $queue = array_combine($all_classes, $all_classes);
	    while (!empty($queue)) {
	    	$name = array_shift($queue);
		    foreach ($this->classResolves[$name] as $pname) {
		    	if (isset($this->classResolves[$pname])) {
				    foreach ($this->classResolves[$pname] as $ppname) {
					    if (!isset($this->classResolves[$name][$ppname])) {
						    $this->classResolves[$name][$ppname] = $ppname;
						    $this->classResolvedBy[$ppname][$name] = $name;
						    $queue[$pname] = $pname;  // propagate up
					    }
				    }
			    }
		    }
		    foreach ($this->classResolvedBy[$name] as $sname) {
		    	if (isset($this->classResolvedBy[$sname])) {
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

	/**
	 * Returns whether the given string is a class in the system loaded as CFG
	 * @return bool
	 */
	public function isClass(string $class_name) {
		return isset($this->classLookup[$class_name]);
	}

	/**
	 * Returns whether the given string is an interface in the system loaded as CFG
	 * @return bool
	 */
	public function isInterface(string $interface_name) {
		return isset($this->interfaceLookup[$interface_name]);
	}

	/**
	 * Returns whether the given string is a trait in the system loaded as CFG
	 * @return bool
	 */
	public function isTrait(string $trait_name) {
		return isset($this->traitLookup[$trait_name]);
	}
}