<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Assign\{CombinedAssignRector};
use Rector\CodeQuality\Rector\BooleanAnd\SimplifyEmptyArrayCheckRector;
use Rector\CodeQuality\Rector\BooleanNot\{ReplaceMultipleBooleanNotRector,
    SimplifyDeMorganBinaryRector};
use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\CodeQuality\Rector\Class_\{CompleteDynamicPropertiesRector,
    InlineConstructorDefaultToPropertyRector};
use Rector\CodeQuality\Rector\ClassMethod\{InlineArrayReturnAssignRector,
    OptionalParametersAfterRequiredRector};
use Rector\CodeQuality\Rector\Concat\JoinStringConcatRector;
use Rector\CodeQuality\Rector\Empty_\SimplifyEmptyCheckOnEmptyArrayRector;
use Rector\CodeQuality\Rector\Equal\UseIdenticalOverEqualWithSameTypeRector;
use Rector\CodeQuality\Rector\Expression\{InlineIfToExplicitIfRector,
    TernaryFalseExpressionToIfRector};
use Rector\CodeQuality\Rector\For_\{ForRepeatedCountToOwnVariableRector};
use Rector\CodeQuality\Rector\Foreach_\{ForeachItemsAssignToEmptyArrayToAssignRector,
    ForeachToInArrayRector,
    SimplifyForeachToCoalescingRector,
    UnusedForeachValueToArrayKeysRector};
use Rector\CodeQuality\Rector\FuncCall\{ArrayMergeOfNonArraysToSimpleArrayRector,
    CallUserFuncWithArrowFunctionToInlineRector,
    ChangeArrayPushToArrayAssignRector,
    CompactToVariablesRector,
    InlineIsAInstanceOfRector,
    IsAWithStringWithThirdArgumentRector,
    RemoveSoleValueSprintfRector,
    SetTypeToCastRector,
    SimplifyFuncGetArgsCountRector,
    SimplifyInArrayValuesRector,
    SimplifyRegexPatternRector,
    SimplifyStrposLowerRector,
    SingleInArrayToCompareRector,
    UnwrapSprintfOneArgumentRector};
use Rector\CodeQuality\Rector\FunctionLike\{SimplifyUselessVariableRector};
use Rector\CodeQuality\Rector\Identical\{BooleanNotIdenticalToNotIdenticalRector,
    FlipTypeControlToUseExclusiveTypeRector,
    SimplifyArraySearchRector,
    SimplifyBoolIdenticalTrueRector,
    SimplifyConditionsRector,
    StrlenZeroToIdenticalEmptyStringRector};
use Rector\CodeQuality\Rector\If_\{CombineIfRector,
    ConsecutiveNullCompareReturnsToNullCoalesceQueueRector,
    ExplicitBoolCompareRector,
    ShortenElseIfRector,
    SimplifyIfElseToTernaryRector,
    SimplifyIfNotNullReturnRector,
    SimplifyIfNullableReturnRector,
    SimplifyIfReturnBoolRector};
use Rector\CodeQuality\Rector\Include_\AbsolutizeRequireAndIncludePathRector;
use Rector\CodeQuality\Rector\Isset_\IssetOnPropertyObjectToPropertyExistsRector;
use Rector\CodeQuality\Rector\LogicalAnd\{AndAssignsToSeparateLinesRector,
    LogicalToBooleanRector};
use Rector\CodeQuality\Rector\New_\NewStaticToNewSelfRector;
use Rector\CodeQuality\Rector\NotEqual\CommonNotEqualRector;
use Rector\CodeQuality\Rector\Switch_\SingularSwitchToIfRector;
use Rector\CodeQuality\Rector\Ternary\{ArrayKeyExistsTernaryThenValueToCoalescingRector,
    SimplifyTautologyTernaryRector,
    SwitchNegatedTernaryRector,
    TernaryEmptyArrayArrayDimFetchToCoalesceRector,
    UnnecessaryTernaryExpressionRector};
use Rector\CodingStyle\Rector\ArrowFunction\StaticArrowFunctionRector;
use Rector\CodingStyle\Rector\Assign\SplitDoubleAssignRector;
use Rector\CodingStyle\Rector\Catch_\CatchExceptionNameMatchingTypeRector;
use Rector\CodingStyle\Rector\ClassConst\{RemoveFinalFromConstRector, SplitGroupedClassConstantsRector};
use Rector\CodingStyle\Rector\ClassMethod\{FuncGetArgsToVariadicParamRector, MakeInheritedMethodVisibilitySameAsParentRector, NewlineBeforeNewAssignSetRector};
use Rector\CodingStyle\Rector\Closure\StaticClosureRector;
use Rector\CodingStyle\Rector\Encapsed\{EncapsedStringsToSprintfRector, WrapEncapsedVariableInCurlyBracesRector};
use Rector\CodingStyle\Rector\FuncCall\{CallUserFuncArrayToVariadicRector, CallUserFuncToMethodCallRector, ConsistentImplodeRector, CountArrayToEmptyArrayComparisonRector, StrictArraySearchRector, VersionCompareFuncCallToConstantRector};
use Rector\CodingStyle\Rector\If_\NullableCompareToNullRector;
use Rector\CodingStyle\Rector\PostInc\PostIncDecToPreIncDecRector;
use Rector\CodingStyle\Rector\Property\{SplitGroupedPropertiesRector};
use Rector\CodingStyle\Rector\String_\{SymplifyQuoteEscapeRector, UseClassKeywordForClassNameResolutionRector};
use Rector\CodingStyle\Rector\Ternary\TernaryConditionVariableAssignmentRector;
use Rector\CodingStyle\Rector\Use_\SeparateMultiUseImportsRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Array_\RemoveDuplicatedArrayKeyRector;
use Rector\DeadCode\Rector\Assign\{RemoveDoubleAssignRector,
    RemoveUnusedVariableAssignRector};
use Rector\DeadCode\Rector\BooleanAnd\RemoveAndTrueRector;
use Rector\DeadCode\Rector\ClassConst\RemoveUnusedPrivateClassConstantRector;
use Rector\DeadCode\Rector\ClassMethod\{RemoveEmptyClassMethodRector,
    RemoveUnusedConstructorParamRector,
    RemoveUnusedPrivateMethodParameterRector,
    RemoveUselessReturnTagRector};
use Rector\DeadCode\Rector\Expression\{RemoveDeadStmtRector,
    SimplifyMirrorAssignRector};
use Rector\DeadCode\Rector\For_\{RemoveDeadContinueRector,
    RemoveDeadIfForeachForRector,
    RemoveDeadLoopRector};
use Rector\DeadCode\Rector\Foreach_\RemoveUnusedForeachKeyRector;
use Rector\DeadCode\Rector\FunctionLike\{RemoveDeadReturnRector};
use Rector\DeadCode\Rector\If_\{RemoveUnusedNonEmptyArrayBeforeForeachRector,
    SimplifyIfElseWithSameContentRector,
    UnwrapFutureCompatibleIfPhpVersionRector};
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\DeadCode\Rector\Plus\RemoveDeadZeroAndOneOperationRector;
use Rector\DeadCode\Rector\Property\{RemoveUnusedPrivatePropertyRector,
    RemoveUselessVarTagRector};
use Rector\DeadCode\Rector\PropertyProperty\RemoveNullPropertyInitializationRector;
use Rector\DeadCode\Rector\Return_\RemoveDeadConditionAboveReturnRector;
use Rector\DeadCode\Rector\StaticCall\RemoveParentCallWithoutParentRector;
use Rector\DeadCode\Rector\Stmt\RemoveUnreachableStatementRector;
use Rector\DeadCode\Rector\Switch_\RemoveDuplicatedCaseInSwitchRector;
use Rector\DeadCode\Rector\Ternary\TernaryToBooleanOrFalseToBooleanAndRector;
use Rector\DeadCode\Rector\TryCatch\RemoveDeadTryCatchRector;
use Rector\EarlyReturn\Rector\Foreach_\ChangeNestedForeachIfsToEarlyContinueRector;
use Rector\EarlyReturn\Rector\If_\{ChangeIfElseValueAssignToEarlyReturnRector,
    ChangeNestedIfsToEarlyReturnRector,
    ChangeOrIfContinueToMultiContinueRector,
    RemoveAlwaysElseRector};
use Rector\EarlyReturn\Rector\Return_\{ReturnBinaryOrToEarlyReturnRector};
use Rector\EarlyReturn\Rector\StmtsAwareInterface\ReturnEarlyIfVariableRector;
use Rector\Naming\Rector\Foreach_\{RenameForeachValueVariableToMatchExprVariableRector,
    RenameForeachValueVariableToMatchMethodCallReturnTypeRector};
use Rector\Php52\Rector\Property\VarToPublicPropertyRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php71\Rector\FuncCall\RemoveExtraParametersRector;
use Rector\Php80\Rector\Catch_\RemoveUnusedVariableInCatchRector;
use Rector\Php80\Rector\Class_\{ClassPropertyAssignToConstructorPromotionRector,
    StringableForToStringRector};
use Rector\Php80\Rector\ClassConstFetch\ClassOnThisVariableObjectRector;
use Rector\Php80\Rector\ClassMethod\{AddParamBasedOnParentClassMethodRector,
    FinalPrivateToPrivateVisibilityRector,
    SetStateToStaticRector};
use Rector\Php80\Rector\FuncCall\{ClassOnObjectRector};
use Rector\Php80\Rector\Identical\{StrEndsWithRector,
    StrStartsWithRector};
use Rector\Php80\Rector\NotIdentical\StrContainsRector;
use Rector\Php80\Rector\Switch_\ChangeSwitchToMatchRector;
use Rector\Php80\Rector\Ternary\GetDebugTypeRector;
use Rector\Php81\Rector\Array_\FirstClassCallableRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Privatization\Rector\ClassMethod\PrivatizeFinalClassMethodRector;
use Rector\Privatization\Rector\Property\{PrivatizeFinalClassPropertyRector};
use Rector\Set\ValueObject\{LevelSetList, SetList};
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use Rector\TypeDeclaration\Rector\Class_\{PropertyTypeFromStrictSetterGetterRector,
    ReturnTypeFromStrictTernaryRector};
use Rector\TypeDeclaration\Rector\ClassMethod\{AddMethodCallBasedStrictParamTypeRector,
    AddParamTypeBasedOnPHPUnitDataProviderRector,
    AddReturnTypeDeclarationBasedOnParentClassMethodRector,
    AddVoidReturnTypeWhereNoReturnRector,
    ParamTypeByMethodCallTypeRector,
    ParamTypeByParentCallTypeRector,
    ReturnNeverTypeRector,
    ReturnTypeFromReturnDirectArrayRector,
    ReturnTypeFromReturnNewRector,
    ReturnTypeFromStrictConstantReturnRector,
    ReturnTypeFromStrictNativeCallRector,
    ReturnTypeFromStrictNewArrayRector,
    ReturnTypeFromStrictTypedCallRector,
    ReturnTypeFromStrictTypedPropertyRector};
use Rector\TypeDeclaration\Rector\Empty_\EmptyOnNullableObjectToInstanceOfRector;
use Rector\TypeDeclaration\Rector\FunctionLike\{AddParamTypeSplFixedArrayRector,
    AddReturnTypeDeclarationFromYieldsRector};
use Rector\TypeDeclaration\Rector\Property\{TypedPropertyFromAssignsRector,
    TypedPropertyFromStrictConstructorRector,
    TypedPropertyFromStrictSetUpRector,};

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_83,
        SetList::TYPE_DECLARATION,
    ])
    ->withConfiguredRule(AddOverrideAttributeToOverriddenMethodsRector::class, [
        'allow_override_empty_method' => false,
    ])
    ->withSkip([
        // Skip StaticClosureRector for PEST test files as PEST doesn't support static test closures
        StaticClosureRector::class => [
            __DIR__ . '/tests/Unit',
        ],
        // Skip StringClassNameToClassConstantRector to avoid Psalm errors with str_contains() on literals
        StringClassNameToClassConstantRector::class,
    ])
    ->withRules([
        // PHP 8.1+ Features
        FirstClassCallableRector::class,
        ReadOnlyPropertyRector::class,

        // PHP 8.2+ Features
        ReadOnlyClassRector::class,

        // Existing rules
        AbsolutizeRequireAndIncludePathRector::class,
        AddArrowFunctionReturnTypeRector::class,
        AddMethodCallBasedStrictParamTypeRector::class,
        AddParamBasedOnParentClassMethodRector::class,
        AddParamTypeBasedOnPHPUnitDataProviderRector::class,
        AddParamTypeSplFixedArrayRector::class,
        AddReturnTypeDeclarationBasedOnParentClassMethodRector::class,
        AddReturnTypeDeclarationFromYieldsRector::class,
        AddVoidReturnTypeWhereNoReturnRector::class,
        AndAssignsToSeparateLinesRector::class,
        ArrayKeyExistsTernaryThenValueToCoalescingRector::class,
        ArrayMergeOfNonArraysToSimpleArrayRector::class,
        BooleanNotIdenticalToNotIdenticalRector::class,
        CallUserFuncArrayToVariadicRector::class,
        CallUserFuncToMethodCallRector::class,
        CallUserFuncWithArrowFunctionToInlineRector::class,
        CatchExceptionNameMatchingTypeRector::class,
        ChangeArrayPushToArrayAssignRector::class,
        ChangeIfElseValueAssignToEarlyReturnRector::class,
        ChangeNestedForeachIfsToEarlyContinueRector::class,
        ChangeNestedIfsToEarlyReturnRector::class,
        ChangeOrIfContinueToMultiContinueRector::class,
        ChangeSwitchToMatchRector::class,
        ClassOnObjectRector::class,
        ClassOnThisVariableObjectRector::class,
        ClassPropertyAssignToConstructorPromotionRector::class,
        CombinedAssignRector::class,
        CombineIfRector::class,
        CommonNotEqualRector::class,
        CompactToVariablesRector::class,
        CompleteDynamicPropertiesRector::class,
        ConsecutiveNullCompareReturnsToNullCoalesceQueueRector::class,
        ConsistentImplodeRector::class,
        CountArrayToEmptyArrayComparisonRector::class,
        EmptyOnNullableObjectToInstanceOfRector::class,
        EncapsedStringsToSprintfRector::class,
        ExplicitBoolCompareRector::class,
        FinalPrivateToPrivateVisibilityRector::class,
        FlipTypeControlToUseExclusiveTypeRector::class,
        ForeachItemsAssignToEmptyArrayToAssignRector::class,
        ForeachToInArrayRector::class,
        ForRepeatedCountToOwnVariableRector::class,
        FuncGetArgsToVariadicParamRector::class,
        GetDebugTypeRector::class,
        InlineArrayReturnAssignRector::class,
        InlineConstructorDefaultToPropertyRector::class,
        InlineIfToExplicitIfRector::class,
        InlineIsAInstanceOfRector::class,
        IsAWithStringWithThirdArgumentRector::class,
        IssetOnPropertyObjectToPropertyExistsRector::class,
        JoinStringConcatRector::class,
        LogicalToBooleanRector::class,
        MakeInheritedMethodVisibilitySameAsParentRector::class,
        NewlineBeforeNewAssignSetRector::class,
        NewStaticToNewSelfRector::class,
        NullableCompareToNullRector::class,
        OptionalParametersAfterRequiredRector::class,
        ParamTypeByMethodCallTypeRector::class,
        ParamTypeByParentCallTypeRector::class,
        PostIncDecToPreIncDecRector::class,
        PrivatizeFinalClassMethodRector::class,
        PrivatizeFinalClassPropertyRector::class,
        PropertyTypeFromStrictSetterGetterRector::class,
        RemoveAlwaysElseRector::class,
        RemoveAndTrueRector::class,
        RemoveDeadConditionAboveReturnRector::class,
        RemoveDeadContinueRector::class,
        RemoveDeadIfForeachForRector::class,
        RemoveDeadLoopRector::class,
        RemoveDeadReturnRector::class,
        RemoveDeadStmtRector::class,
        RemoveDeadTryCatchRector::class,
        RemoveDeadZeroAndOneOperationRector::class,
        RemoveDoubleAssignRector::class,
        RemoveDuplicatedArrayKeyRector::class,
        RemoveDuplicatedCaseInSwitchRector::class,
        RemoveEmptyClassMethodRector::class,
        RemoveExtraParametersRector::class,
        RemoveFinalFromConstRector::class,
        RemoveNonExistingVarAnnotationRector::class,
        RemoveNullPropertyInitializationRector::class,
        RemoveParentCallWithoutParentRector::class,
        RemoveSoleValueSprintfRector::class,
        RemoveUnreachableStatementRector::class,
        RemoveUnusedConstructorParamRector::class,
        RemoveUnusedForeachKeyRector::class,
        RemoveUnusedNonEmptyArrayBeforeForeachRector::class,
        RemoveUnusedPrivateClassConstantRector::class,
        RemoveUnusedPrivateMethodParameterRector::class,
        RemoveUnusedPrivatePropertyRector::class,
        RemoveUnusedVariableAssignRector::class,
        RemoveUnusedVariableInCatchRector::class,
        RemoveUselessReturnTagRector::class,
        RemoveUselessVarTagRector::class,
        RenameForeachValueVariableToMatchExprVariableRector::class,
        RenameForeachValueVariableToMatchMethodCallReturnTypeRector::class,
        ReplaceMultipleBooleanNotRector::class,
        ReturnBinaryOrToEarlyReturnRector::class,
        ReturnEarlyIfVariableRector::class,
        ReturnNeverTypeRector::class,
        ReturnTypeFromReturnDirectArrayRector::class,
        ReturnTypeFromReturnNewRector::class,
        ReturnTypeFromStrictConstantReturnRector::class,
        ReturnTypeFromStrictNativeCallRector::class,
        ReturnTypeFromStrictNewArrayRector::class,
        ReturnTypeFromStrictTernaryRector::class,
        ReturnTypeFromStrictTypedCallRector::class,
        ReturnTypeFromStrictTypedPropertyRector::class,
        SeparateMultiUseImportsRector::class,
        SetStateToStaticRector::class,
        SetTypeToCastRector::class,
        ShortenElseIfRector::class,
        SimplifyArraySearchRector::class,
        SimplifyBoolIdenticalTrueRector::class,
        SimplifyConditionsRector::class,
        SimplifyDeMorganBinaryRector::class,
        SimplifyEmptyArrayCheckRector::class,
        SimplifyEmptyCheckOnEmptyArrayRector::class,
        SimplifyForeachToCoalescingRector::class,
        SimplifyFuncGetArgsCountRector::class,
        SimplifyIfElseToTernaryRector::class,
        SimplifyIfElseWithSameContentRector::class,
        SimplifyIfNotNullReturnRector::class,
        SimplifyIfNullableReturnRector::class,
        SimplifyIfReturnBoolRector::class,
        SimplifyInArrayValuesRector::class,
        SimplifyMirrorAssignRector::class,
        SimplifyRegexPatternRector::class,
        SimplifyStrposLowerRector::class,
        SimplifyTautologyTernaryRector::class,
        SimplifyUselessVariableRector::class,
        SingleInArrayToCompareRector::class,
        SingularSwitchToIfRector::class,
        SplitDoubleAssignRector::class,
        SplitGroupedClassConstantsRector::class,
        SplitGroupedPropertiesRector::class,
        StaticArrowFunctionRector::class,
        StaticClosureRector::class,
        StrContainsRector::class,
        StrEndsWithRector::class,
        StrictArraySearchRector::class,
        StringableForToStringRector::class,
        StrlenZeroToIdenticalEmptyStringRector::class,
        StrStartsWithRector::class,
        SwitchNegatedTernaryRector::class,
        SymplifyQuoteEscapeRector::class,
        TernaryConditionVariableAssignmentRector::class,
        TernaryEmptyArrayArrayDimFetchToCoalesceRector::class,
        TernaryFalseExpressionToIfRector::class,
        TernaryToBooleanOrFalseToBooleanAndRector::class,
        ThrowWithPreviousExceptionRector::class,
        TypedPropertyFromAssignsRector::class,
        TypedPropertyFromStrictConstructorRector::class,
        TypedPropertyFromStrictSetUpRector::class,
        UnnecessaryTernaryExpressionRector::class,
        UnusedForeachValueToArrayKeysRector::class,
        UnwrapFutureCompatibleIfPhpVersionRector::class,
        UnwrapSprintfOneArgumentRector::class,
        UseClassKeywordForClassNameResolutionRector::class,
        UseIdenticalOverEqualWithSameTypeRector::class,
        VarToPublicPropertyRector::class,
        VersionCompareFuncCallToConstantRector::class,
        WrapEncapsedVariableInCurlyBracesRector::class,
    ]);
