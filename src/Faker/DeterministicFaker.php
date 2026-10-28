<?php
declare(strict_types=1);

namespace RockSoftware\DbSanitizer\Faker;

use Faker\Factory;
use Faker\Generator;
use InvalidArgumentException;
use RockSoftware\DbSanitizer\Rule;

/**
 * Realistic fake values that depend only on the original and a key.
 *
 * The same original gives the same fake in every table and every run with the
 * same key, so a customer stays one customer across a copy. The key is the
 * production salt by default (`DbSanitizer.key`): masking runs on the
 * production server, and without the key a fake cannot be traced back by
 * guessing originals.
 */
final class DeterministicFaker
{
    private Generator $faker;

    /**
     * @param string $key The secret the fakes are derived with.
     * @param string $locale Faker's locale.
     */
    public function __construct(private string $key, string $locale = 'fi_FI')
    {
        if ($key === '') {
            throw new InvalidArgumentException('The masking key is empty.');
        }
        $this->faker = Factory::create($locale);
    }

    /**
     * A fake of one kind for an original.
     *
     * @param string $kind A `Rule` fake kind.
     * @param string $original The original value.
     * @return string
     */
    public function fake(string $kind, string $original): string
    {
        $hash = hash_hmac('sha256', $kind . "\0" . $original, $this->key);
        $this->faker->seed((int)hexdec(substr($hash, 0, 7)));
        $tag = substr($hash, 0, 8);

        return match ($kind) {
            Rule::FIRST_NAME => $this->faker->firstName(),
            Rule::LAST_NAME => $this->faker->lastName(),
            Rule::FULL_NAME => $this->faker->firstName() . ' ' . $this->faker->lastName(),
            // The tag keeps two originals from meeting in a unique index.
            Rule::EMAIL => sprintf(
                '%s.%s@example.test',
                self::slug($this->faker->firstName() . '.' . $this->faker->lastName()),
                $tag,
            ),
            Rule::PHONE => '+35840' . str_pad((string)(hexdec(substr($hash, 0, 7)) % 10000000), 7, '0', STR_PAD_LEFT),
            Rule::STREET_ADDRESS => $this->faker->streetAddress(),
            Rule::POSTAL_CODE => $this->faker->postcode(),
            Rule::CITY => $this->faker->city(),
            Rule::COMPANY => $this->faker->company(),
            Rule::TEXT => $this->text(mb_strlen($original)),
            Rule::IDENTIFIER => self::identifier($hash, $original),
            default => throw new InvalidArgumentException(sprintf('"%s" is not a fake kind.', $kind)),
        };
    }

    /**
     * Sentences about as long as the original, at least a few words.
     *
     * @param int $length The original's length in characters.
     * @return string
     */
    private function text(int $length): string
    {
        $text = '';
        do {
            $text .= ($text === '' ? '' : ' ') . $this->faker->sentence();
        } while (mb_strlen($text) < $length);

        return mb_substr($text, 0, max($length, 20));
    }

    /**
     * Letters and digits in the original's places, so a plate number still
     * looks like one and fits its column.
     *
     * @param string $hash The hash to draw from.
     * @param string $original The original.
     * @return string
     */
    private static function identifier(string $hash, string $original): string
    {
        $letters = 'ABCDEFGHJKLMNPRSTUVXYZ';
        $out = '';
        $length = strlen($original);
        for ($i = 0; $i < $length; $i++) {
            $char = $original[$i];
            $byte = hexdec($hash[($i * 2) % 64] . $hash[($i * 2 + 1) % 64]);
            if (ctype_digit($char)) {
                $out .= (string)($byte % 10);
            } elseif (ctype_alpha($char)) {
                $letter = $letters[$byte % strlen($letters)];
                $out .= ctype_lower($char) ? strtolower($letter) : $letter;
            } else {
                $out .= $char;
            }
        }

        return $out;
    }

    /**
     * An email-safe, lower-case form of a name.
     *
     * @param string $name The name.
     * @return string
     */
    private static function slug(string $name): string
    {
        $ascii = strtr(mb_strtolower($name), ['ä' => 'a', 'ö' => 'o', 'å' => 'a', 'é' => 'e', 'ü' => 'u']);

        return trim((string)preg_replace('/[^a-z0-9.]+/', '', $ascii), '.');
    }
}
