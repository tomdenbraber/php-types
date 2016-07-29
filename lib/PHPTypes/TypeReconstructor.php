<?php

/*
 * This file is part of PHP-Types, a type reconstruction lib for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPTypes;

use PHPCfg\Assertion;
use PHPCfg\Op;
use PHPCfg\Operand;
use SplObjectStorage;

class TypeReconstructor {
	/** @var  State */
	protected $state;

	public function resolve(State $state) {
		$this->state = $state;
		// First resolve properties
		$this->resolveAllProperties();
		$resolved = new SplObjectStorage;
		$unresolved = new SplObjectStorage;
		foreach ($state->variables as $op) {
			if (!empty($op->type) && $op->type->type !== Type::TYPE_UNKNOWN) {
				$resolved[$op] = $op->type;
			} elseif ($op instanceof Operand\BoundVariable && $op->scope === Operand\BoundVariable::SCOPE_OBJECT && $op->extra !== null) {
				assert($op->extra instanceof Operand\Literal);
				$resolved[$op] = $op->type = Type::fromDecl($op->extra->value);
			} elseif ($op instanceof Operand\Literal) {
				$resolved[$op] = $op->type = Type::fromValue($op->value);
			} else {
				$unresolved[$op] = Type::unknown();
			}
		}

		if (count($unresolved) === 0) {
			// short-circuit
			return;
		}

		$round = 1;
		do {
			$start = count($resolved);
			$toRemove = [];
			foreach ($unresolved as $k => $var) {
				$type = $this->resolveVar($var, $resolved);
				if ($type) {
					$toRemove[] = $var;
					$resolved[$var] = $type;
				}
			}
			foreach ($toRemove as $remove) {
				$unresolved->detach($remove);
			}
		} while (count($unresolved) > 0 && $start < count($resolved));
		foreach ($resolved as $var) {
			$var->type = $resolved[$var];
		}
		foreach ($unresolved as $var) {
			$var->type = $unresolved[$var];
		}
	}

	protected function resolveVar(Operand $var, SplObjectStorage $resolved) {
		$types = [];
		/** @var Op $prev */
		foreach ($var->ops as $prev) {
			try {
				$type = $this->resolveVarOp($var, $prev, $resolved);
				if ($type) {
					if (!is_array($type)) {
						throw new \LogicException('Handler returned a non-array');
					}
					foreach ($type as $t) {
						if ($t instanceof Type === false) {
							throw new \LogicException('Handler returned non-type');
						}
						$types[] = $t;
					}
				} else {
					return false;
				}
			} catch (\Exception $e) {
				throw new \LogicException(sprintf('Exception raised while handling op %s@%s:%d', $prev->getType(), $prev->getFile(), $prev->getLine()), 0, $e);
			}
		}
		if (empty($types)) {
			return false;
		}
		return Type::union($types);
	}

	protected function resolveVarOp(Operand $var, Op $op, SplObjectStorage $resolved) {
		$method = 'resolveOp_' . $op->getType();
		if (method_exists($this, $method)) {
			return call_user_func([$this, $method], $var, $op, $resolved);
		}
		switch ($op->getType()) {
			case 'Expr_InstanceOf':
			case 'Expr_BinaryOp_Equal':
			case 'Expr_BinaryOp_NotEqual':
			case 'Expr_BinaryOp_Greater':
			case 'Expr_BinaryOp_GreaterOrEqual':
			case 'Expr_BinaryOp_Identical':
			case 'Expr_BinaryOp_NotIdentical':
			case 'Expr_BinaryOp_Smaller':
			case 'Expr_BinaryOp_SmallerOrEqual':
			case 'Expr_BinaryOp_LogicalAnd':
			case 'Expr_BinaryOp_LogicalOr':
			case 'Expr_BinaryOp_LogicalXor':
			case 'Expr_BooleanNot':
			case 'Expr_Cast_Bool':
			case 'Expr_Empty':
			case 'Expr_Isset':
				return [Type::bool()];
			case 'Expr_BinaryOp_BitwiseAnd':
			case 'Expr_BinaryOp_BitwiseOr':
			case 'Expr_BinaryOp_BitwiseXor':
				if ($resolved->contains($op->left) && $resolved->contains($op->right)) {
					switch ([$resolved[$op->left]->type, $resolved[$op->right]->type]) {
						case [Type::TYPE_STRING, Type::TYPE_STRING]:
							return [Type::string()];
						default:
							return [Type::int()];
					}
				}
				return false;
			case 'Expr_BitwiseNot':
				if ($resolved->contains($op->expr)) {
					switch ($resolved[$op->expr]->type) {
						case Type::TYPE_STRING:
							return [Type::string()];
						default:
							return [Type::int()];
					}
				}
				return false;
			case 'Expr_BinaryOp_Div':
			case 'Expr_BinaryOp_Plus':
			case 'Expr_BinaryOp_Minus':
			case 'Expr_BinaryOp_Mul':
				if ($resolved->contains($op->left) && $resolved->contains($op->right)) {
					switch ([$resolved[$op->left]->type, $resolved[$op->right]->type]) {
						case [Type::TYPE_LONG, Type::TYPE_LONG]:
							return [Type::int()];
						case [Type::TYPE_DOUBLE, TYPE::TYPE_LONG]:
						case [Type::TYPE_LONG, TYPE::TYPE_DOUBLE]:
						case [Type::TYPE_DOUBLE, TYPE::TYPE_DOUBLE]:
							return [Type::float()];
						case [Type::TYPE_ARRAY, Type::TYPE_ARRAY]:
							$sub = Type::union(array_merge($resolved[$op->left]->subTypes, $resolved[$op->right]->subTypes));
							if ($sub) {
								return [new Type(Type::TYPE_ARRAY, [$sub])];
							}
							return [new Type(Type::TYPE_ARRAY)];
						default:
							return [Type::mixed()];
							throw new \RuntimeException("Math op on unknown types {$resolved[$op->left]} + {$resolved[$op->right]}");
					}
				}
				return false;
			case 'Expr_BinaryOp_Concat':
			case 'Expr_Cast_String':
			case 'Expr_ConcatList':
				return [Type::string()];
			case 'Expr_BinaryOp_Mod':
			case 'Expr_BinaryOp_ShiftLeft':
			case 'Expr_BinaryOp_ShiftRight':
			case 'Expr_Cast_Int':
			case 'Expr_Print':
				return [Type::int()];
			case 'Expr_Cast_Double':
				return [Type::float()];
			case 'Expr_UnaryMinus':
			case 'Expr_UnaryPlus':
				if ($resolved->contains($op->expr)) {
					switch ($resolved[$op->expr]->type) {
						case Type::TYPE_LONG:
						case Type::TYPE_DOUBLE:
							return [$resolved[$op->expr]];
					}
					return [Type::numeric()];
				}
				return false;
			case 'Expr_Eval':
				return false;
			case 'Iterator_Key':
				if ($resolved->contains($op->var)) {
					// TODO: implement this as well
					return false;
				}
				return false;
			case 'Expr_Exit':
			case 'Iterator_Reset':
				return [Type::null()];
			case 'Iterator_Valid':
				return [Type::bool()];
			case 'Iterator_Value':
				if ($resolved->contains($op->var)) {
					if ($resolved[$op->var]->subTypes) {
						return $resolved[$op->var]->subTypes;
					}
					return false;
				}
				return false;
			case 'Expr_Yield':
			case 'Expr_Include':
				// TODO: we may be able to determine these...
				return false;
		}
		throw new \LogicException("Unknown variable op found: " . $op->getType());
	}

	protected function resolveOp_Expr_Array(Operand $var, Op\Expr\Array_ $op, SplObjectStorage $resolved) {
		$types = [];
		foreach ($op->values as $value) {
			if (!isset($resolved[$value])) {
				return false;
			}
			$types[] = $resolved[$value];
		}
		if (empty($types)) {
			return [new Type(Type::TYPE_ARRAY)];
		}
		$r = Type::union($types);
		if ($r) {
			return [new Type(Type::TYPE_ARRAY, [$r])];
		}
		return [new Type(Type::TYPE_ARRAY)];
	}

	protected function resolveOp_Expr_Cast_Array(Operand $var, Op\Expr\Cast\Array_ $op, SplObjectStorage $resolved) {
		// Todo: determine subtypes better
		return [new Type(Type::TYPE_ARRAY)];
	}

	protected function resolveOp_Expr_ArrayDimFetch(Operand $var, Op\Expr\ArrayDimFetch $op, SplObjectStorage $resolved) {
		if ($resolved->contains($op->var)) {
			// Todo: determine subtypes better
			$type = $resolved[$op->var];
			if ($type->subTypes) {
				return $type->subTypes;
			}
			if ($type->type === Type::TYPE_STRING) {
				return [$type];
			}
			return [Type::mixed()];
		}
		return false;
	}

	protected function resolveOp_Expr_Assign(Operand $var, Op\Expr\Assign $op, SplObjectStorage $resolved) {
		if ($resolved->contains($op->expr)) {
			return [$resolved[$op->expr]];
		}
		return false;
	}

	protected function resolveOp_Expr_AssignRef(Operand $var, Op\Expr\AssignRef $op, SplObjectStorage $resolved) {
		if ($resolved->contains($op->expr)) {
			return [$resolved[$op->expr]];
		}
		return false;
	}

	protected function resolveOp_Expr_Cast_Object(Operand $var, Op\Expr\Cast\Object_ $op, SplObjectStorage $resolved) {
		if ($resolved->contains($op->expr)) {
			if ($this->state->resolver->resolves($resolved[$op->expr], Type::object())) {
				return [$resolved[$op->expr]];
			}
			return [new Type(Type::TYPE_OBJECT, [], 'stdClass')];
		}
		return false;
	}

	protected function resolveOp_Expr_Clone(Operand $var, Op\Expr\Clone_ $op, SplObjectStorage $resolved) {
		if ($resolved->contains($op->expr)) {
			return [$resolved[$op->expr]];
		}
		return false;
	}

	protected function resolveOp_Expr_Closure(Operand $var, Op\Expr\Closure $op, SplObjectStorage $resolved) {
		return [new Type(Type::TYPE_OBJECT, [], "Closure")];
	}

	protected function resolveOp_Expr_FuncCall(Operand $var, Op\Expr\FuncCall $op, SplObjectStorage $resolved) {
		if ($op->name instanceof Operand\Literal) {
			$name = strtolower($op->name->value);
			if (isset($this->state->functionLookup[$name])) {
				return $this->resolveFunctionsType($this->state->functionLookup[$name]);
			} else {
				return $this->resolveInternalFunctionType($name);
			}
		}
		// we can't resolve the function
		return false;
	}

	protected function resolveOp_Expr_NsFuncCall(Operand $var, Op\Expr\NsFuncCall $op, SplObjectStorage $resolved) {
		assert($op->nsName instanceof Operand\Literal);
		assert($op->name instanceof Operand\Literal);
		$functions = null;
		$nsName = strtolower($op->nsName->value);
		$name = strtolower($op->name->value);
		if (isset($this->state->functionLookup[$nsName])) {
			$functions = $this->state->functionLookup[$nsName];
		} elseif (isset($this->state->functionLookup[$name])) {
			$functions = $this->state->functionLookup[$name];
		}

		if ($functions !== null) {
			return $this->resolveFunctionsType($functions);
		}

		return $this->resolveInternalFunctionType($name);
	}

	protected function resolveOp_Expr_MethodCall(Operand $var, Op\Expr\MethodCall $op, SplObjectStorage $resolved) {
		if ($op->name instanceof Operand\Literal) {
			$name = strtolower($op->name->value);
			$classnames = $this->resolveClassNames($op->var, $resolved);
			if (!empty($classnames)) {
				$types = [];
				foreach ($classnames as $classname) {
					$types = array_merge($types, $this->resolvePolymorphicMethodCall($classname, $name, false));
				}
				if (!empty($types)) {
					return $types;
				}
			}
		}
		return false;
	}

	protected function resolveOp_Expr_StaticCall(Operand $var, Op\Expr\StaticCall $op, SplObjectStorage $resolved) {
		if ($op->name instanceof Operand\Literal) {
			$name = strtolower($op->name->value);
			$classnames = $this->resolveClassNames($op->class, $resolved);
			if (!empty($classnames)) {
				$types = [];
				foreach ($classnames as $classname) {
					$types = array_merge($types, $this->resolvePolymorphicMethodCall($classname, $name, true));
				}
				if (!empty($types)) {
					return $types;
				}
			}
		}
		return false;
	}

	protected function resolveOp_Expr_New(Operand $var, Op\Expr\New_ $op, SplObjectStorage $resolved) {
		$type = $this->getClassType($op->class, $resolved);
		if ($type) {
			return [$type];
		}
		return [Type::object()];
	}

	protected function resolveOp_Expr_Param(Operand $var, Op\Expr\Param $op, SplObjectStorage $resolved) {
		assert(isset($op->function->callableOp));
		$docType = Type::extractTypeFromComment("param", $op->function->callableOp->getAttribute('doccomment'), $op->name->value);
		if ($op->type) {
			$type = Type::fromDecl($op->type->value);
			if ($op->defaultVar) {
				if (!empty($op->defaultBlock->children) && $op->defaultBlock->children[0]->getType() === "Expr_ConstFetch" && strtolower($op->defaultBlock->children[0]->name->value) === "null") {
					$type = (new Type(Type::TYPE_UNION, [$type, Type::null()]))->simplify();
				}
			}
			if ($docType !== Type::mixed() && $this->state->resolver->resolves($docType, $type)) {
				// return the more specific
				return [$docType];
			}
			return [$type];
		}
		return [$docType];
	}

	protected function resolveOp_Expr_PropertyFetch(Operand $var, Op\Expr\PropertyFetch $op, SplObjectStorage $resolved) {
		if ($op->name instanceof Operand\Literal) {
			$propname = strtolower($op->name->value);
			$classnames = $this->resolveClassNames($op->var, $resolved);
			if (!empty($classnames)) {
				$types = [];
				foreach ($classnames as $classname) {
					$types = array_merge($types, $this->resolvePolymorphicProperty($classname, $propname, false));
				}
				if (!empty($types)) {
					return $types;
				}
			}
		}
		return false;
	}

	protected function resolveOp_Expr_StaticPropertyFetch(Operand $var, Op\Expr\StaticPropertyFetch $op, SplObjectStorage $resolved) {
		if ($op->name instanceof Operand\Literal) {
			$propname = strtolower($op->name->value);
			$classnames = $this->resolveClassNames($op->class, $resolved);
			if (!empty($classname)) {
				$types = [];
				foreach ($classnames as $classname) {
					$types = array_merge($types, $this->resolvePolymorphicProperty($classname, $propname, true));
				}
				if (!empty($types)) {
					return $types;
				}
			}
		}
		return false;
	}

	protected function resolveOp_Expr_Assertion(Operand $var, Op $op, SplObjectStorage $resolved) {
		$tmp = $this->processAssertion($op->assertion, $op->expr, $resolved);
		if ($tmp) {
			return [$tmp];
		}
		return false;
	}

	protected function resolveOp_Expr_ConstFetch(Operand $var, Op\Expr\ConstFetch $op, SplObjectStorage $resolved) {
		if ($op->name instanceof Operand\Literal) {
			$constant = strtolower($op->name->value);
			switch ($constant) {
				case 'true':
				case 'false':
					return [Type::bool()];
				case 'null':
					return [Type::null()];
				default:
					if (isset($this->state->constants[$op->name->value])) {
						$return = [];
						foreach ($this->state->constants[$op->name->value] as $value) {
							if (!$resolved->contains($value->value)) {
								return false;
							}
							$return[] = $resolved[$value->value];
						}
						return $return;
					}
			}
		}
		return false;
	}

	protected function resolveOp_Expr_ClassConstFetch(Operand $var, Op\Expr\ClassConstFetch $op, SplObjectStorage $resolved) {
		$classnames = $this->resolveClassNames($op->class, $resolved);
		if (!empty($classnames)) {
			$types = [];
			foreach ($classnames as $classname) {
				assert($op->name instanceof Operand\Literal);
				$constname = strtolower($op->name->value);
				$types = array_merge($types, $this->resolvePolymorphicClassConstant($classname, $constname));
			}
			if (!empty($types)) {
				return $types;
			}
		}
		return false;
	}

	protected function resolveOp_Terminal_StaticVar(Operand $var, Op\Terminal\StaticVar $op, SplObjectStorage $resolved) {
		if ($op->defaultVar === null) {
			return [Type::null()];
		}
		if ($resolved->contains($op->defaultVar)) {
			return [$resolved[$op->defaultVar]];
		}
		return false;
	}

	protected function resolveOp_Phi(Operand $var, Op\Phi $op, SplObjectStorage $resolved) {
		$types = [];
		$resolveFully = true;
		foreach ($op->vars as $v) {
			if ($resolved->contains($v)) {
				$types[] = $resolved[$v];
			} else {
				$resolveFully = false;
			}
		}
		if (empty($types)) {
			return false;
		}
		$type = Type::union($types);
		if ($type) {
			if ($resolveFully) {
				return [$type];
			}
			// leave on unresolved list to try again next round
			$resolved[$var] = $type;
		}
		return false;
	}

	protected function resolveAllProperties() {
		foreach ($this->state->classes as $class) {
			foreach ($class->stmts->children as $stmt) {
				if ($stmt instanceof Op\Stmt\Property) {
					$stmt->type = Type::extractTypeFromComment("var", $stmt->getAttribute('doccomment'));
				}
			}
		}
	}

	private function resolveFunctionsType($functions) {
		assert(!empty($functions));
		$result = [];
		foreach ($functions as $function) {
			$func = $function->func;
			if ($func->returnType) {
				$result[] = Type::fromDecl($func->returnType->value);
			} else {
				// Check doc comment
				$result[] = Type::extractTypeFromComment("return", $function->getAttribute('doccomment'));
			}
		}
		return $result;
	}

	private function resolveInternalFunctionType($name) {
		if (isset($this->state->internalTypeInfo->functions[$name])) {
			$type = $this->state->internalTypeInfo->functions[$name];
			if (empty($type['return'])) {
				return false;
			}
			return [Type::fromDecl($type['return'])];
		}

		return false;
	}

	private function resolveClassNames(Operand $operand, SplObjectStorage $resolved) {
		$classnames = [];
		if ($resolved->contains($operand)) {
			$type = $resolved[$operand];
			if ($type->type === Type::TYPE_STRING) {
				if ($operand instanceof Operand\Literal) {
					$classnames[] = strtolower($operand->value);
				}
			} else {
				$classnames = array_merge($classnames, $this->resolveClassNamesFromUserTypes($type));
			}
		}
		return $classnames;
	}

	private function resolveClassNamesFromUserTypes(Type $type) {
		$classnames = [];
		switch($type->type) {
			case Type::TYPE_OBJECT:
				$classnames[] = strtolower($type->userType);
				break;
			case Type::TYPE_UNION:
				foreach ($type->subTypes as $subType) {
					$classnames = array_merge($classnames, $this->resolveClassNamesFromUserTypes($subType));
				}
				break;
		}
		return $classnames;
	}

	/**
	 * @param string $classname
	 * @param string $methodname
	 * @param bool $is_static_call
	 * @return Type[]
	 */
	private function resolvePolymorphicMethodCall($classname, $methodname, $is_static_call) {
		$alltypes = [];
		if (isset($this->state->classResolvedBy[$classname])) {
			foreach ($this->state->classResolvedBy[$classname] as $sclassname) {
				$classtypes = $this->resolveMethodCall($sclassname, $methodname);
				if (!empty($classtypes)) {
					$alltypes = array_merge($alltypes, $classtypes);
				} else {
					$classtypes = $this->resolveMethodCall($sclassname, $is_static_call ? '__callStatic' : '__call');
					if (!empty($classtypes)) {
						$alltypes = array_merge($alltypes, $classtypes);
					}
				}
			}
		}
		return $alltypes;
	}

	/**
	 * @param string $classname
	 * @param string $methodname
	 * @return Type[]
	 */
	private function resolveMethodCall($classname, $methodname) {
		$types = [];
		if (isset($this->state->classLookup[$classname])) {
			/** @var Op\Stmt\Class_ $class */
			foreach ($this->state->classLookup[$classname] as $class) {
				if (isset($this->state->methodLookup[$class][$methodname])) {
					/** @var Op\Stmt\ClassMethod $method */
					foreach ($this->state->methodLookup[$class][$methodname] as $method) {
						$doctype = Type::extractTypeFromComment("return", $method->getAttribute('doccomment'));
						$func = $method->getFunc();
						if ($func->returnType) {
							$decltype = Type::fromDecl($func->returnType->value);
							$types[] = $this->state->resolver->resolves($doctype, $decltype) ? $doctype : $decltype;
						} else {
							$types[] = $doctype;
						}
					}
				} else if ($class->extends !== null) {
					assert($class->extends instanceof Operand\Literal);
					foreach ($this->resolveMethodCall(strtolower($class->extends->value), $methodname) as $type) {
						$types[] = $type;
					}
				}
			}
		}

		// try resolve in builtins - we do this as well to support monkey patching
		$type = $this->resolveBuiltinMethodCall($classname, $methodname);
		if ($type !== null) {
			$types[] = $type;
		}
		return $types;
	}

	/**
	 * @param string $classname
	 * @param string $methodname
	 * @return Type
	 */
	private function resolveBuiltinMethodCall($classname, $methodname) {
		$type = null;
		if (isset($this->state->internalTypeInfo->methods[$classname][$methodname])) {
			$methodInfo = $this->state->internalTypeInfo->methods[$classname][$methodname];
			if (isset($methodInfo['return'])) {
				$type = Type::fromDecl($methodInfo['return']);
			}
		} else if (isset($this->state->internalTypeInfo->classExtends[$classname])) {
			$type = $this->resolveBuiltinMethodCall($this->state->internalTypeInfo->classExtends[$classname], $methodname);
		}
		return $type;
	}

	/**
	 * @param string $classname
	 * @param string $propname
	 * @param bool $is_static_access
	 * @return Type[]
	 */
	private function resolvePolymorphicProperty($classname, $propname, $is_static_access) {
		$alltypes = [];
		if (isset($this->state->classResolvedBy[$classname])) {
			foreach ($this->state->classResolvedBy[$classname] as $sclassname) {
				$classtypes = $this->resolveProperty($sclassname, $propname);
				if (!empty($alltypes)) {
					$alltypes = array_merge($alltypes, $classtypes);
				} else if (!$is_static_access) {
					$classtypes = $this->resolveMethodCall($sclassname, '__get');
					if (!empty($classtypes)) {
						$alltypes = array_merge($alltypes, $classtypes);
					}
				}
			}
		}
		return $alltypes;
	}

	/**
	 * @param string $classname
	 * @param string $propname
	 * @return Type[]
	 */
	private function resolveProperty($classname, $propname) {
		$types = [];
		if (isset($this->state->classLookup[$classname])) {
			foreach ($this->state->classLookup[$classname] as $class) {
				if (isset($this->state->propertyLookup[$class][$propname])) {
					foreach ($this->state->propertyLookup[$class][$propname] as $prop) {
						if (isset($prop->type)) {
							$types[] = $prop->type;
						}
					}
				} else if ($class->extends !== null) {
					foreach ($this->resolveProperty(strtolower($class->extends->value), $propname) as $type) {
						$types[] = $type;
					}
				}
			}
		}

		// try resolve in builtins - we do this as well to support monkey patching
		$type = $this->resolveBuiltinProperty($classname, $propname);
		if ($type !== null) {
			$types[] = $type;
		}
		return $types;
	}

	/**
	 * @param string $classname
	 * @param string $propname
	 * @return Type
	 */
	private function resolveBuiltinProperty($classname, $propname) {
		$type = null;
		if (isset($this->state->internalTypeInfo->properties[$classname][$propname])) {
			$type = Type::fromDecl($this->state->internalTypeInfo->properties[$classname][$propname]);
		} else if (isset($this->state->internalTypeInfo->classExtends[$classname])) {
			$type = $this->resolveBuiltinProperty($this->state->internalTypeInfo->classExtends[$classname], $propname);
		}
		return $type;
	}

	/**
	 * @param string $classname
	 * @param string $constname
	 * @return Type[]
	 */
	private function resolvePolymorphicClassConstant($classname, $constname) {
		$types = [];
		if (isset($this->state->classResolvedBy[$classname])) {
			foreach ($this->state->classResolvedBy[$classname] as $sclassname) {
				foreach ($this->resolveClassConstant($sclassname, $constname) as $type) {
					$types[] = $type;
				}
			}
		}
		return $types;
	}

	/**
	 * @param string $classname
	 * @param string $constname
	 * @return Type[]
	 */
	private function resolveClassConstant($classname, $constname) {
		$types = [];
		if (isset($this->state->classLookup[$classname])) {
			foreach ($this->state->classLookup[$classname] as $class) {
				if (isset($this->state->classConstantLookup[$class][$constname])) {
					foreach ($this->state->classConstantLookup[$class][$constname] as $classconst) {
						if ($classconst->value->type !== null) {
							$types[] = $classconst->value->type;
						}
					}
				} else if ($class->extends !== null) {
					foreach ($this->resolveClassConstant(strtolower($class->extends->value), $constname) as $type) {
						$types[] = $type;
					}
				}
			}
		}

		// try resolve in builtins - we do this as well to support monkey patching
		$type = $this->resolveBuiltinClassConstant($classname, $constname);
		if ($type !== null) {
			$types[] = $type;
		}
		return $types;
	}

	/**
	 * @param string $classname
	 * @param string $constname
	 * @return Type
	 */
	private function resolveBuiltinClassConstant($classname, $constname) {
		$type = null;
		if (isset($this->state->internalTypeInfo->classConstants[$classname][$constname])) {
			$type = Type::fromDecl($this->state->internalTypeInfo->classConstants[$classname][$constname]);
		} else if (isset($this->state->internalTypeInfo->classExtends[$classname])) {
			$type = $this->resolveBuiltinClassConstant($this->state->internalTypeInfo->classExtends[$classname], $constname);
		}
		return $type;
	}

	protected function getClassType(Operand $var, SplObjectStorage $resolved) {
		if ($var instanceof Operand\Literal) {
			return new Type(Type::TYPE_OBJECT, [], $var->value);
		} elseif ($var instanceof Operand\BoundVariable && $var->scope === Operand\BoundVariable::SCOPE_OBJECT && $var->extra !== null) {
			assert($var->extra instanceof Operand\Literal);
			return Type::fromDecl($var->extra->value);
		} elseif ($resolved->contains($var)) {
			$type = $resolved[$var];
			if ($type->type === Type::TYPE_OBJECT) {
				return $type;
			}
		}
		// We don't know the type
		return false;
	}

	protected function processAssertion(Assertion $assertion, Operand $source, SplObjectStorage $resolved) {
		if ($assertion instanceof Assertion\TypeAssertion) {
			$tmp = $this->processTypeAssertion($assertion, $source, $resolved);
			if ($tmp) {
				return $tmp;
			}
		} elseif ($assertion instanceof Assertion\NegatedAssertion) {
			$op = $this->processAssertion($assertion->value[0], $source, $resolved);
			if ($op instanceof Type) {
				// negated type assertion
				if (isset($resolved[$source])) {
					return $resolved[$source]->removeType($op);
				}
				// Todo, figure out how to wait for resolving
				return Type::mixed()->removeType($op);
			}
		}
		return false;
	}

	protected function processTypeAssertion(Assertion\TypeAssertion $assertion, Operand $source, SplObjectStorage $resolved) {
		if ($assertion->value instanceof Operand) {
			if ($assertion->value instanceof Operand\Literal) {
				return Type::fromDecl($assertion->value->value);
			}
			if (isset($resolved[$assertion->value])) {
				return $resolved[$assertion->value];
			}
			return false;
		}
		$subTypes = [];
		foreach ($assertion->value as $sub) {
			$subTypes[] = $subType = $this->processTypeAssertion($sub, $source, $resolved);
			if (!$subType) {
				// Not fully resolved yet
				return false;
			}
		}
		$type = $assertion->mode === Assertion::MODE_UNION ? Type::TYPE_UNION : Type::TYPE_INTERSECTION;
		return new Type($type, $subTypes);
	}
}