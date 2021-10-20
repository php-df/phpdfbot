<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Str;

/**
 * Class Contains
 *
 * @author Marcelo Rodovalho <rodovalhomf@gmail.com>
 */
class Contains implements Rule
{
    /** @var array */
    private $words = [];

    /** @var array */
    private $foundWords = [];

    /**
     * Create a new rule instance.
     *
     * @param array $words
     */
    public function __construct(array $words)
    {
        $this->words = $words;
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param string $attribute
     * @param mixed  $value
     *
     * @return bool
     */
    public function passes($attribute, $value): bool
    {
        // Revert the result of CONTAINS: IF contains return FALSE
        $passes = blank($this->words) || !Str::contains(mb_strtolower($value), $this->words);
        if (!$passes) {
            $pattern = '/\b(?:' . implode('|', $this->words) . ')\b/i';
            if (preg_match_all($pattern, $value, $matches)) {
                $this->foundWords = $matches[0];
            }
        }
        return $passes;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return trans('validation.contains', ['words' => implode(', ', $this->foundWords)]);
    }
}
