<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * A command's signature as plain data — what the form is built from and what submitted
 * input is checked against. Captured from Symfony's InputDefinition in a console process
 * (see {@see Catalog}) and stored, so the control panel never has to boot Artisan.
 *
 * @phpstan-type ArgumentSpec array{name: string, required: bool, array: bool, description: string, default: string|list<string>|null}
 * @phpstan-type OptionSpec array{name: string, shortcut: ?string, value: 'none'|'required'|'optional', array: bool, description: string, default: string|list<string>|bool|null}
 */
final readonly class CommandSpec
{
    /** Symfony/Laravel's application-wide options: never offered, never accepted. */
    public const array GLOBAL_OPTIONS = ['help', 'quiet', 'silent', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env'];

    /**
     * @param  list<ArgumentSpec>  $arguments
     * @param  list<OptionSpec>  $options
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $arguments,
        public array $options,
    ) {}

    public static function fromCommand(Command $command): self
    {
        return self::fromDefinition((string) $command->getName(), $command->getDescription(), $command->getDefinition());
    }

    public static function fromDefinition(string $name, string $description, InputDefinition $definition): self
    {
        $arguments = [];
        foreach ($definition->getArguments() as $argument) {
            $arguments[] = [
                'name' => $argument->getName(),
                'required' => $argument->isRequired(),
                'array' => $argument->isArray(),
                'description' => $argument->getDescription(),
                'default' => self::scalarDefault($argument->getDefault()),
            ];
        }

        $options = [];
        foreach ($definition->getOptions() as $option) {
            if (in_array($option->getName(), self::GLOBAL_OPTIONS, true)) {
                continue;
            }
            $options[] = [
                'name' => $option->getName(),
                'shortcut' => $option->getShortcut() ?: null,
                'value' => match (true) {
                    ! $option->acceptValue() => 'none',
                    $option->isValueRequired() => 'required',
                    default => 'optional',
                },
                'array' => $option->isArray(),
                'description' => $option->getDescription(),
                'default' => $option->acceptValue() ? self::scalarDefault($option->getDefault()) : false,
            ];
        }

        return new self($name, $description, $arguments, $options);
    }

    /** @param  array<array-key, mixed>  $data */
    public static function fromArray(array $data): self
    {
        /** @var list<ArgumentSpec> $arguments */
        $arguments = array_values(array_filter((array) ($data['arguments'] ?? []), 'is_array'));
        /** @var list<OptionSpec> $options */
        $options = array_values(array_filter((array) ($data['options'] ?? []), 'is_array'));

        return new self((string) ($data['name'] ?? ''), (string) ($data['description'] ?? ''), $arguments, $options);
    }

    /** @return array{name: string, description: string, arguments: list<ArgumentSpec>, options: list<OptionSpec>} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'description' => $this->description, 'arguments' => $this->arguments, 'options' => $this->options];
    }

    /** Rebuild a Symfony definition, so submitted input can be bound and validated by Symfony itself. */
    public function definition(): InputDefinition
    {
        $definition = new InputDefinition;

        foreach ($this->arguments as $a) {
            $mode = ($a['required'] ? InputArgument::REQUIRED : InputArgument::OPTIONAL) | ($a['array'] ? InputArgument::IS_ARRAY : 0);
            $definition->addArgument(new InputArgument($a['name'], $mode, $a['description']));
        }

        foreach ($this->options as $o) {
            $mode = match ($o['value']) {
                'none' => InputOption::VALUE_NONE,
                'required' => InputOption::VALUE_REQUIRED,
                default => InputOption::VALUE_OPTIONAL,
            } | ($o['array'] && $o['value'] !== 'none' ? InputOption::VALUE_IS_ARRAY : 0);
            $definition->addOption(new InputOption($o['name'], null, $mode, $o['description']));
        }

        return $definition;
    }

    /** @return OptionSpec|null */
    public function option(string $name): ?array
    {
        foreach ($this->options as $o) {
            if ($o['name'] === $name) {
                return $o;
            }
        }

        return null;
    }

    /** @return string|list<string>|null */
    private static function scalarDefault(mixed $default): string|array|null
    {
        if (is_array($default)) {
            return array_values(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $default));
        }

        return is_scalar($default) ? (string) $default : null;
    }
}
