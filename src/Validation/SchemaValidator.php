<?php

declare(strict_types=1);

namespace Waypost\Validation;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use Waypost\Schema\Paths;

/**
 * Validates decoded JSON (objects as stdClass, as json_decode() gives them by default) against a schema of
 * waypost/schema, e.g. "api/auth-login.request". The errors come as Hungarian messages keyed by the
 * dot-separated path of the field (api/error.json details): a missing or unknown property under its own path.
 */
final class SchemaValidator
{
    private const int MAX_ERRORS = 100;

    private const array TYPE_NAMES = [
        'string' => 'szöveg',
        'integer' => 'egész szám',
        'number' => 'szám',
        'boolean' => 'logikai érték',
        'object' => 'objektum',
        'array' => 'tömb',
        'null' => 'null',
    ];

    private readonly Validator $validator;

    public function __construct()
    {
        $this->validator = new Validator(max_errors: self::MAX_ERRORS, stop_at_first_error: false);
        $this->validator->resolver()?->registerPrefix(Paths::SCHEMA_ID_BASE, Paths::schemaDirectory() . '/');
    }

    /** The error tree of opis/json-schema, null if the data is valid. */
    public function validate(mixed $data, string $schema): ?ValidationError
    {
        return $this->validator->validate($data, Paths::schemaId($schema))->error();
    }

    /**
     * Hungarian messages keyed by field path; empty if the data is valid.
     *
     * @return array<string, list<string>>
     */
    public function errors(mixed $data, string $schema): array
    {
        $error = $this->validate($data, $schema);
        if ($error === null) {
            return [];
        }

        $messages = [];
        $nullable = [];
        foreach (self::leaves($error) as $leaf) {
            foreach (self::messages($leaf) as [$path, $message]) {
                // "must be null", the other branch of a nullable field: only if nothing else is wrong there.
                if ($leaf->keyword() === 'type' && $leaf->args()['expected'] === 'null') {
                    $nullable[$path] = $message;

                    continue;
                }
                $messages[$path][] = $message;
            }
        }
        foreach ($nullable as $path => $message) {
            $messages[$path] ??= [$message];
        }

        return array_map(fn (array $list): array => array_values(array_unique($list)), $messages);
    }

    /** @return iterable<ValidationError> */
    private static function leaves(ValidationError $error): iterable
    {
        $children = $error->subErrors();
        if ($children === []) {
            yield $error;

            return;
        }
        foreach ($children as $child) {
            yield from self::leaves($child);
        }
    }

    /**
     * The messages of a leaf error with their paths.
     *
     * @return list<array{string, string}>
     */
    private static function messages(ValidationError $error): array
    {
        $path = array_values($error->data()->fullPath());
        $args = $error->args();

        return match ($error->keyword()) {
            'required' => self::each($path, $args['missing'], 'Kötelező mező.'),
            'additionalProperties' => self::each($path, self::undeclared($error, $args['properties']), 'Ismeretlen mező.'),
            'properties' => self::each($path, [$args['property']], 'Ez a mező nem megengedett.'),
            default => [[self::key($path), self::message($error->keyword(), $args)]],
        };
    }

    /**
     * @param  list<int|string>  $path
     * @param  list<int|string>  $names
     * @return list<array{string, string}>
     */
    private static function each(array $path, array $names, string $message): array
    {
        return array_map(fn (int|string $name): array => [self::key([...$path, $name]), $message], $names);
    }

    /**
     * The properties the schema does not declare. Once a declared property fails, opis/json-schema does not count
     * it as checked, and lists it with the unknown ones.
     *
     * @return list<int|string>
     */
    private static function undeclared(ValidationError $error, mixed $names): array
    {
        $schema = $error->schema()->info()->data();
        $declared = is_object($schema) && isset($schema->properties) && is_object($schema->properties)
            ? array_keys(get_object_vars($schema->properties))
            : [];

        return array_values(array_filter(
            is_array($names) ? $names : [],
            fn (mixed $name): bool => (is_int($name) || is_string($name)) && !in_array((string) $name, $declared, true),
        ));
    }

    /** @param  list<int|string>  $path */
    private static function key(array $path): string
    {
        return implode('.', $path);
    }

    /** @param  array<string, mixed>  $args */
    private static function message(string $keyword, array $args): string
    {
        $min = self::scalar($args['min'] ?? null);
        $max = self::scalar($args['max'] ?? null);

        return match ($keyword) {
            'type' => 'Hibás típus, ' . self::typeNames($args['expected'] ?? []) . ' kell.',
            'minLength' => $min === '1' ? 'Nem lehet üres.' : "Legalább {$min} karakter legyen.",
            'maxLength' => "Legfeljebb {$max} karakter lehet.",
            'minimum' => "Legalább {$min} legyen.",
            'maximum' => "Legfeljebb {$max} lehet.",
            'exclusiveMinimum' => "Nagyobb legyen, mint {$min}.",
            'exclusiveMaximum' => "Kisebb legyen, mint {$max}.",
            'minItems' => $min === '1' ? 'Nem lehet üres.' : "Legalább {$min} elem kell.",
            'maxItems' => "Legfeljebb {$max} elem lehet.",
            'uniqueItems' => 'Az elemek nem ismétlődhetnek.',
            'minProperties' => $min === '1' ? 'Nem lehet üres.' : "Legalább {$min} mező kell.",
            'maxProperties' => "Legfeljebb {$max} mező lehet.",
            'format' => ($args['format'] ?? null) === 'email' ? 'Érvénytelen e-mail-cím.' : 'Hibás formátum.',
            'pattern' => 'Hibás formátum.',
            'enum', 'const' => 'Nem megengedett érték.',
            default => 'Érvénytelen érték.',
        };
    }

    private static function typeNames(mixed $types): string
    {
        $names = array_map(fn (mixed $type): string => self::TYPE_NAMES[self::scalar($type)] ?? self::scalar($type), (array) $types);

        return implode(' vagy ', $names);
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
