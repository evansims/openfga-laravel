<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setRules([
        // Array handling
        'array_indentation' => true,
        'array_push' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_multiline_whitespace_around_double_arrow' => true,
        'no_whitespace_before_comma_in_array' => true,
        'normalize_index_brace' => true,
        'trim_array_spaces' => true,
        'whitespace_after_comma_in_array' => true,

        // Assignment and operators
        'assign_null_coalescing_to_coalesce_equal' => true,
        'binary_operator_spaces' => ['default' => 'single_space'],
        'concat_space' => ['spacing' => 'one'],
        'no_useless_concat_operator' => true,
        'no_useless_nullsafe_operator' => true,
        'operator_linebreak' => ['only_booleans' => true, 'position' => 'beginning'],
        'standardize_increment' => true,
        'standardize_not_equals' => true,
        'ternary_operator_spaces' => true,
        'ternary_to_elvis_operator' => true,
        'ternary_to_null_coalescing' => true,
        'unary_operator_spaces' => true,

        // Blank lines and spacing
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => true,
        'blank_line_before_statement' => [
            'statements' => ['break', 'case', 'continue', 'declare', 'default', 'do', 'exit', 'for', 'foreach', 'goto', 'if', 'include', 'include_once', 'phpdoc', 'require', 'require_once', 'return', 'switch', 'throw', 'try', 'while', 'yield', 'yield_from'],
        ],
        'blank_line_between_import_groups' => true,
        'no_blank_lines_after_class_opening' => true,
        'no_blank_lines_after_phpdoc' => true,
        'no_extra_blank_lines' => [
            'tokens' => ['attribute', 'break', 'case', 'continue', 'curly_brace_block', 'default', 'extra', 'parenthesis_brace_block', 'return', 'square_brace_block', 'switch', 'throw', 'use'],
        ],
        'no_whitespace_in_blank_line' => true,
        'single_blank_line_at_eof' => true,
        'single_blank_line_before_namespace' => true,

        // Braces and control structures
        'braces_position' => [
            'allow_single_line_anonymous_functions' => true,
            'allow_single_line_empty_anonymous_classes' => true,
            'anonymous_classes_opening_brace' => 'same_line',
            'anonymous_functions_opening_brace' => 'same_line',
            'classes_opening_brace' => 'next_line_unless_newline_at_signature_end',
            'control_structures_opening_brace' => 'same_line',
            'functions_opening_brace' => 'next_line_unless_newline_at_signature_end',
        ],
        'elseif' => true,
        'no_superfluous_elseif' => true,
        'no_useless_else' => true,
        'simplified_if_return' => true,
        'switch_case_semicolon_to_colon' => true,
        'switch_case_space' => true,
        'switch_continue_to_break' => true,

        // Casing
        'class_reference_name_casing' => true,
        'constant_case' => ['case' => 'lower'],
        'integer_literal_case' => true,
        'lowercase_cast' => true,
        'lowercase_keywords' => true,
        'lowercase_static_reference' => true,
        'magic_constant_casing' => true,
        'magic_method_casing' => true,
        'native_function_casing' => true,
        'native_function_type_declaration_casing' => true,

        // Class definition and elements
        'class_attributes_separation' => [
            'elements' => [
                'case' => 'one',
                'const' => 'one',
                'method' => 'one',
                'property' => 'one',
                'trait_import' => 'one',
            ],
        ],
        'class_definition' => [
            'inline_constructor_arguments' => false,
            'multi_line_extends_each_single_line' => true,
            'single_item_single_line' => true,
            'single_line' => true,
            'space_before_parenthesis' => false,
        ],
        'final_class' => true,
        'final_internal_class' => true,
        'no_unneeded_final_method' => true,
        'ordered_class_elements' => [
            'order' => [
                'use_trait',
                'case',
                'constant',
                'constant_public',
                'constant_protected',
                'constant_private',
                'property_public_readonly',
                'property_public_static',
                'property_public',
                'property_protected_readonly',
                'property_protected_static',
                'property_protected',
                'property_private_readonly',
                'property_private_static',
                'property_private',
                'construct',
                'destruct',
                'magic',
                'phpunit',
                'method_abstract',
                'method_public_abstract_static',
                'method_public_abstract',
                'method_public_static',
                'method_public',
                'method_protected_abstract_static',
                'method_protected_abstract',
                'method_protected_static',
                'method_protected',
                'method_private_abstract_static',
                'method_private_abstract',
                'method_private_static',
                'method_private',
            ],
            'sort_algorithm' => 'alpha',
        ],
        'protected_to_private' => true,
        'self_accessor' => true,
        'single_class_element_per_statement' => true,
        'single_trait_insert_per_statement' => true,
        'visibility_required' => ['elements' => ['const', 'method', 'property']],

        // Comments and PHPDoc
        'comment_to_phpdoc' => [
            'ignored_tags' => ['codeCoverageIgnoreStart', 'codeCoverageIgnoreEnd', 'phpstan-ignore-next-line', 'psalm-suppress'],
        ],
        'multiline_comment_opening_closing' => true,
        'no_empty_comment' => true,
        'no_empty_phpdoc' => true,
        'no_trailing_whitespace_in_comment' => true,
        'phpdoc_add_missing_param_annotation' => ['only_untyped' => false],
        'phpdoc_align' => ['align' => 'vertical'],
        'phpdoc_indent' => true,
        'phpdoc_inline_tag_normalizer' => true,
        'phpdoc_line_span' => ['const' => 'multi', 'method' => 'multi', 'property' => 'multi'],
        'phpdoc_no_access' => true,
        'phpdoc_no_empty_return' => true,
        'phpdoc_no_package' => true,
        'phpdoc_no_useless_inheritdoc' => true,
        'phpdoc_order_by_value' => ['annotations' => ['covers', 'coversNothing', 'dataProvider', 'depends', 'group', 'internal', 'requires', 'throws', 'uses']],
        'phpdoc_order' => ['order' => ['param', 'throws', 'return']],
        'phpdoc_return_self_reference' => ['replacements' => ['this' => 'self']],
        'phpdoc_scalar' => ['types' => ['boolean', 'callback', 'double', 'integer', 'real', 'str']],
        'phpdoc_separation' => ['groups' => [['deprecated', 'link', 'see', 'since'], ['author', 'copyright', 'license'], ['category', 'package', 'subpackage'], ['property', 'property-read', 'property-write'], ['param', 'return']]],
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_summary' => true,
        'phpdoc_tag_type' => ['tags' => ['api' => 'annotation', 'author' => 'annotation', 'copyright' => 'annotation', 'deprecated' => 'annotation', 'example' => 'annotation', 'global' => 'annotation', 'inheritDoc' => 'annotation', 'internal' => 'annotation', 'license' => 'annotation', 'method' => 'annotation', 'package' => 'annotation', 'param' => 'annotation', 'property' => 'annotation', 'return' => 'annotation', 'see' => 'annotation', 'since' => 'annotation', 'throws' => 'annotation', 'todo' => 'annotation', 'uses' => 'annotation', 'var' => 'annotation', 'version' => 'annotation']],
        'phpdoc_to_comment' => ['ignored_tags' => ['var']],
        'phpdoc_trim_consecutive_blank_line_separation' => true,
        'phpdoc_trim' => true,
        'phpdoc_types_order' => ['null_adjustment' => 'always_last', 'sort_algorithm' => 'alpha'],
        'phpdoc_types' => ['groups' => ['simple', 'alias', 'meta']],
        'phpdoc_var_annotation_correct_order' => true,
        'phpdoc_var_without_name' => true,
        'single_line_comment_spacing' => true,
        'single_line_comment_style' => ['comment_types' => ['hash']],

        // Control flow
        'combine_consecutive_issets' => true,
        'combine_consecutive_unsets' => true,
        'empty_loop_body' => ['style' => 'braces'],
        'empty_loop_condition' => ['style' => 'while'],
        'no_break_comment' => ['comment_text' => 'no break'],
        'no_unneeded_control_parentheses' => ['statements' => ['break', 'clone', 'continue', 'echo_print', 'others', 'return', 'switch_case', 'yield', 'yield_from']],
        'no_unreachable_default_argument_value' => true,
        'simplified_if_return' => true,

        // Function and method handling
        'function_declaration' => ['closure_function_spacing' => 'one'],
        'function_typehint_space' => true,
        'implode_call' => true,
        'lambda_not_used_import' => true,
        'method_argument_space' => [
            'after_heredoc' => true,
            'on_multiline' => 'ensure_fully_multiline',
        ],
        'method_chaining_indentation' => true,
        'no_spaces_after_function_name' => true,
        'nullable_type_declaration_for_default_null_value' => ['use_nullable_type_declaration' => true],
        'regular_callable_call' => true,
        'return_assignment' => true,
        'return_type_declaration' => ['space_before' => 'none'],
        'single_line_throw' => true,
        'use_arrow_functions' => true,
        'void_return' => true,

        // Imports and namespaces
        'clean_namespace' => true,
        'fully_qualified_strict_types' => ['import_symbols' => true],
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => true,
            'import_functions' => true,
        ],
        'group_import' => true,
        'no_leading_import_slash' => true,
        'no_leading_namespace_whitespace' => true,
        'no_unneeded_import_alias' => true,
        'no_unused_imports' => true,
        'ordered_imports' => [
            'imports_order' => ['const', 'class', 'function'],
            'sort_algorithm' => 'alpha',
        ],
        'single_import_per_statement' => false, // Allow grouped imports
        'single_line_after_imports' => true,

        // Language constructs
        'declare_equal_normalize' => ['space' => 'none'],
        'declare_parentheses' => true,
        'declare_strict_types' => true,
        'echo_tag_syntax' => ['format' => 'long'],
        'include' => true,
        'is_null' => true,
        'list_syntax' => ['syntax' => 'short'],
        'logical_operators' => true,
        'new_with_braces' => ['anonymous_class' => false, 'named_class' => false],
        'no_alias_functions' => ['sets' => ['@all']],
        'no_alias_language_construct_call' => true,
        'no_alternative_syntax' => ['fix_non_monolithic_code' => false],
        'single_space_after_construct' => ['constructs' => ['abstract', 'as', 'attribute', 'break', 'case', 'catch', 'class', 'clone', 'comment', 'const', 'const_import', 'continue', 'do', 'echo', 'else', 'elseif', 'enum', 'extends', 'final', 'finally', 'for', 'foreach', 'function', 'function_import', 'global', 'goto', 'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'interface', 'match', 'named_argument', 'namespace', 'new', 'open_tag_with_echo', 'php_doc', 'php_open', 'print', 'private', 'protected', 'public', 'readonly', 'require', 'require_once', 'return', 'static', 'switch', 'throw', 'trait', 'try', 'use', 'use_lambda', 'use_trait', 'var', 'while', 'yield', 'yield_from']],
        'single_space_around_construct' => true,

        // Modern PHP features (8.0+)
        'attribute_empty_parentheses' => ['use_parentheses' => false],
        'date_time_create_from_format_call' => true,
        'date_time_immutable' => true,
        'get_class_to_class_keyword' => true,
        'modernize_strpos' => true,
        'modernize_types_casting' => true,
        'no_unset_cast' => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'octal_notation' => true,
        'pow_to_exponentiation' => true,
        'random_api_migration' => [
            'replacements' => [
                'getrandmax' => 'mt_getrandmax',
                'rand' => 'mt_rand',
                'srand' => 'mt_srand',
            ],
        ],
        'set_type_to_cast' => true,
        'ternary_to_null_coalescing' => true,
        'trailing_comma_in_multiline' => [
            'after_heredoc' => true,
            'elements' => ['arguments', 'arrays', 'match', 'parameters'],
        ],

        // Naming and references
        'backtick_to_shell_exec' => true,
        'combine_nested_dirname' => true,
        'dir_constant' => true,
        'function_to_constant' => [
            'functions' => ['get_called_class', 'get_class', 'get_class_this', 'php_sapi_name', 'phpversion', 'pi'],
        ],
        'no_homoglyph_names' => true,
        'self_static_accessor' => true,

        // Semicolons and statements
        'multiline_whitespace_before_semicolons' => ['strategy' => 'no_multi_line'],
        'no_empty_statement' => true,
        'no_singleline_whitespace_before_semicolons' => true,
        'semicolon_after_instruction' => true,
        'space_after_semicolon' => ['remove_in_empty_for_expressions' => true],

        // Strict comparisons and type safety
        'compact_nullable_typehint' => true,
        'no_short_bool_cast' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'types_spaces' => ['space' => 'single', 'space_multiple_catch' => 'single'],

        // String handling
        'escape_implicit_backslashes' => [
            'double_quoted' => true,
            'heredoc_syntax' => true,
            'single_quoted' => false,
        ],
        'explicit_indirect_variable' => true,
        'explicit_string_variable' => true,
        'heredoc_indentation' => ['indentation' => 'start_plus_one'],
        'heredoc_to_nowdoc' => true,
        'no_binary_string' => true,
        'no_trailing_whitespace_in_string' => true,
        'simple_to_complex_string_variable' => true,
        'single_quote' => ['strings_containing_single_quote_chars' => false],
        'string_length_to_empty' => true,
        'string_line_ending' => true,

        // Tags and encoding
        'encoding' => true,
        'full_opening_tag' => true,
        'linebreak_after_opening_tag' => true,
        'no_closing_tag' => true,
        'no_multiple_statements_per_line' => true,

        // Type casting and conversion
        'cast_spaces' => ['space' => 'single'],
        'lowercase_cast' => true,
        'modernize_types_casting' => true,
        'no_unset_cast' => true,
        'set_type_to_cast' => true,
        'short_scalar_cast' => true,

        // Whitespace and indentation
        'indentation_type' => true,
        'line_ending' => true,
        'no_spaces_around_offset' => ['positions' => ['inside', 'outside']],
        'no_spaces_inside_parenthesis' => true,
        'no_trailing_whitespace' => true,
        'object_operator_without_whitespace' => true,
        'statement_indentation' => ['stick_comment_to_next_continuous_control_statement' => true],

        // PSR compliance
        'psr_autoloading' => ['dir' => null],

        // Error suppression and debugging
        'error_suppression' => [
            'mute_deprecation_error' => true,
            'noise_remaining_usages' => false,
            'noise_remaining_usages_exclude' => [],
        ],
        'no_useless_sprintf' => true,

        // Miscellaneous
        'no_php4_constructor' => true,
        'non_printable_character' => ['use_escape_sequences_in_strings' => false],
        'not_operator_with_successor_space' => true,
        'yoda_style' => [
            'always_move_variable' => false,
            'equal' => true,
            'identical' => true,
            'less_and_greater' => true,
        ],

        // Additional modern PHP rules
        'clean_namespace' => true,
        'no_useless_return' => true,
        'ordered_interfaces' => ['direction' => 'ascend', 'order' => 'alpha'],
        'ordered_traits' => true,
        'php_unit_fqcn_annotation' => true,

        // Additional alignment with other tools
        'final_class' => true, // Aligns with Rector's privatization rules
        'declare_strict_types' => true, // Aligns with PHPStan/Psalm strictness
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => true], // Performance optimization

        'phpdoc_annotation_without_dot' => true,        // Remove trailing periods from annotations
        'phpdoc_no_alias_tag' => true,                  // Use @return instead of @returns
        'phpdoc_tag_casing' => true,                    // Standardize tag casing
        'general_phpdoc_annotation_remove' => [         // Remove deprecated annotations
            'annotations' => ['author', 'package', 'subpackage']
        ],
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->exclude('vendor')
            ->in([
                __DIR__ . '/src/',
                __DIR__ . '/tests/',
            ])
            ->append([__DIR__ . '/rector.php']),
    );
