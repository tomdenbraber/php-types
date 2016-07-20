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
            } elseif ($op instanceof Operand\BoundVariable && $op->scope === Operand\BoundVariable::SCOPE_OBJECT) {
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

    /**
     * @param Type[] $types
     *
     * @return Type
     */
    protected function computeMergedType(array $types) {
        if (count($types) === 1) {
            return $types[0];
        }
        $same = null;
        foreach ($types as $key => $type) {
            if (!$type instanceof Type) {
                var_dump($types);
                throw new \RuntimeException("Invalid type found");
            }
            if (is_null($same)) {
                $same = $type;
            } elseif ($same && !$same->equals($type)) {
                $same = false;
            }
            if ($type->type === Type::TYPE_UNKNOWN) {
                return false;
            }
        }
        if ($same) {
            return $same;
        }
        return (new Type(Type::TYPE_UNION, $types))->simplify();
    }

    protected function resolveVar(Operand $var, SplObjectStorage $resolved) {
        $types = [];
        foreach ($var->ops as $prev) {
            $type = $this->resolveVarOp($var, $prev, $resolved);
            if ($type) {
                if (!is_array($type)) {
                    throw new \LogicException("Handler for " . get_class($prev) . " returned a non-array");
                }
                foreach ($type as $t) {
                    assert($t instanceof Type);
                    $types[] = $t;
                }
            } else {
                return false;
            }
        }
        if (empty($types)) {
            return false;
        }
        return $this->computeMergedType($types);
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
                            $sub = $this->computeMergedType(array_merge($resolved[$op->left]->subTypes, $resolved[$op->right]->subTypes));
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
        $r = $this->computeMergedType($types);
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
			$classname = $this->resolveClassName($op->var, $resolved);
			if ($classname) {
				$types = $this->resolvePolymorphicMethodCall($classname, $name);
				if (!empty($types)) {
					return $types;
				}
				$types = $this->resolvePolymorphicMethodCall($classname, '__call');
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
			$classname = $this->resolveClassName($op->class, $resolved);
			if ($classname) {
				$types = $this->resolvePolymorphicMethodCall($classname, $name);
				if (!empty($types)) {
					return $types;
				}
				$types = $this->resolvePolymorphicMethodCall($classname, '__callstatic');
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
		    $classname = $this->resolveClassName($op->var, $resolved);
		    if ($classname !== null) {
			    $types = $this->resolvePolymorphicProperty($classname, $propname);
			    if (!empty($types)) {
				    return $types;
			    }
			    $types = $this->resolvePolymorphicMethodCall($classname, '__get');
			    if (!empty($types)) {
				    return $types;
			    }
		    }
	    }
    }

	protected function resolveOp_Expr_StaticPropertyFetch(Operand $var, Op\Expr\StaticPropertyFetch $op, SplObjectStorage $resolved) {
		if ($op->name instanceof Operand\Literal) {
			$propname = strtolower($op->name->value);
			$classname = $this->resolveClassName($op->class, $resolved);
			if ($classname !== null) {
				$types = $this->resolvePolymorphicProperty($classname, $propname);
				if (!empty($types)) {
					return $types;
				}
			}
		}
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
        $classes = [];
        if ($op->class instanceof Operand\Literal) {
            $class = strtolower($op->class->value);
            return $this->resolveClassConstant($class, $op, $resolved);
        } elseif ($resolved->contains($op->class)) {
            $type = $resolved[$op->class];
            if ($type->type !== Type::TYPE_OBJECT || empty($type->userType)) {
                // give up
                return false;
            }
            return $this->resolveClassConstant(strtolower($type->userType), $op, $resolved);
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
        $type = $this->computeMergedType($types);
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

	private function resolveClassName($class, SplObjectStorage $resolved) {
		if ($resolved->contains($class)) {
			if ($resolved[$class]->type === Type::TYPE_STRING) {
				if ($class instanceof Operand\Literal) {
					$userType = $class->value;
				}
			} else if ($resolved[$class]->type === Type::TYPE_OBJECT) {
				$userType = $resolved[$class]->userType;
			}
		}

		if (isset($userType)) {
			return strtolower($userType);
		}
	}

	private function resolvePolymorphicMethodCall($classname, $methodname) {
		$types = [];
		if (isset($this->state->classResolvedBy[$classname])) {
			foreach ($this->state->classResolvedBy[$classname] as $sclassname) {
				foreach ($this->resolveMethodCall($sclassname, $methodname) as $type) {
					$types[] = $type;
				}
			}
		}
		return $types;
	}

	private function resolveMethodCall($classname, $methodname) {
		$types = [];
		if (isset($this->state->methodLookup[$classname][$methodname])) {
			/** @var Op\Stmt\ClassMethod $classmethod */
			foreach ($this->state->methodLookup[$classname][$methodname] as $classmethod) {
				$doctype = Type::extractTypeFromComment("return", $classmethod->getAttribute('doccomment'));
				$func = $classmethod->getFunc();
				if ($func->returnType) {
					$decltype = Type::fromDecl($func->returnType->value);
					$types[] = $this->state->resolver->resolves($doctype, $decltype) ? $doctype : $decltype;
				} else {
					$types[] = $doctype;
				}
			}
		} else if (isset($this->state->internalTypeInfo->methods[$classname][$methodname])) {
			$method = $this->state->internalTypeInfo->methods[$classname][$methodname];
			if (isset($method['return'])) {
				$types[] = Type::fromDecl($method['return']);
			}
		} else if (isset($this->state->classExtends[$classname])) {
			foreach ($this->state->classExtends[$classname] as $pclassname) {
				foreach ($this->resolveMethodCall($pclassname, $methodname) as $type) {
					$types[] = $type;
				}
			}
		}
		return $types;
	}

	private function resolvePolymorphicProperty($classname, $propertyname) {
		$types = [];
		if (isset($this->state->classResolvedBy[$classname])) {
			foreach ($this->state->classResolvedBy[$classname] as $sclassname) {
				foreach ($this->resolveProperty($sclassname, $propertyname) as $type) {
					$types[] = $type;
				}
			}
		}
		return $types;
	}

	/**
	 * @param string $classname
	 * @param string $propname
	 * @return Type[]
	 */
	private function resolveProperty($classname, $propname) {
		$types = [];
		if (isset($this->state->propertyLookup[$classname][$propname])) {
			/** @var Op\Stmt\Property $classprop */
			foreach ($this->state->propertyLookup[$classname][$propname] as $classprop) {
				if (isset($classprop->type)) {
					$types[] = $classprop->type;
				}
			}
		}
		if (isset($this->state->internalTypeInfo->properties[$classname][$propname]['type'])) {
			return $this->state->internalTypeInfo->properties[$classname][$propname]['type'];
		}
		return $types;
	}

    protected function resolveClassConstant($class, $op, SplObjectStorage $resolved) {
        $try = $class . '::' . $op->name->value;
        if (isset($this->state->constants[$try])) {
            $types = [];
            foreach ($this->state->constants[$try] as $const) {
                if ($resolved->contains($const->value)) {
                    $types[] = $resolved[$const->value];
                } else {
                    // Not every
                    return false;
                }
            }
            return $types;
        }
        if (!isset($this->state->classResolvedBy[$class])) {
            // can't find classes
            return false;
        }
        $types = [];
        foreach ($this->state->classResolves[$class] as $name => $_) {
            $try = $name . '::' . $op->name->value;
            if (isset($this->state->constants[$try])) {
                foreach ($this->state->constants[$try] as $const) {
                    if ($resolved->contains($const->value)) {
                        $types[] = $resolved[$const->value];
                    } else {
                        // Not every is resolved yet
                        return false;
                    }
                }
            }
        }
        if (empty($types)) {
            return false;
        }
        return $types;
    }

    protected function getClassType(Operand $var, SplObjectStorage $resolved) {
        if ($var instanceof Operand\Literal) {
            return new Type(Type::TYPE_OBJECT, [], $var->value);
        } elseif ($var instanceof Operand\BoundVariable && $var->scope === Operand\BoundVariable::SCOPE_OBJECT) {
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