<?php 

namespace Imbrish\LetsEncrypt;

class Command {
    /**
     * The CLImate instance.
     * 
     * @var \League\CLImate\CLImate
     */
    public static $climate;

    /**
     * The configuration array.
     * 
     * @var array
     */
    public static $config;

    /**
     * Command aliases.
     * 
     * @var array
     */
    public static $aliases = [];

    /**
     * Last executed command.
     * 
     * @var string
     */
    public static $last;

    /**
     * Result of last executed command.
     * 
     * @var int
     */
    public static $result;

    /**
     * Output of last executed command.
     * 
     * @var string
     */
    public static $output = '';

    /**
     * Non escaped command without arguments.
     * 
     * @var string
     */
    protected $cmd;

    /**
     * Escaped command parts.
     * 
     * @var array
     */
    protected $parts = [];

    /**
     * Execute command and return result code.
     *
     * @param string $cmd
     * @param array $args
     *
     * @return int
     */
    public static function exec($cmd, $args = [])
    {
        return call_user_func(new static($cmd, $args));
    }

    /**
     * Construct new command instance.
     *
     * @param string $cmd
     * @param array $args
     *
     * @return void
     */
    public function __construct($cmd, $args = [])
    {
        if (array_key_exists($cmd, static::$aliases)) {
            $cmd = static::$aliases[$cmd];
        }

        $parts = array_merge((array) $cmd, $args);

        $this->cmd = reset($parts);

        $this->insertParts($this->parts, $parts);
    }

    /**
     * Insert command parts at a given position or at the end.
     *
     * @return array &$parts
     * @return int $pos
     * @return array $new
     *
     * @return void
     */
    protected function insertParts(&$parts, $pos, $new = null)
    {
        if (is_array($pos)) {
            list($pos, $new) = [count($pos), $pos];
        }

        // convert keyed parts into options and escape where necessary
        $parsed = [];

        foreach ($new as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (strpos($value, ' ') !== false) {
                $value = escapeshellarg($value);
            }

            if (! is_int($key)) {
                $value = $key . '=' . $value;
            }

            $parsed[] = $value;
        }

        array_splice($parts, $pos, 0, $parsed);
    }

    /**
     * Print and remember last command.
     *
     * @param string $command
     *
     * @return void
     */
    protected function printCommand($command)
    {
        static::$last = $command;

        if (static::$config['verbose_enabled']) {
            static::$climate->comment($command);
        }
    }

    /**
     * Print and remember output.
     *
     * @param string $output
     *
     * @return void
     */
    protected function printOutput($output)
    {
        // remove leading whitespace from every line and all empty lines
        $output = preg_replace('/^[\t ]*[\n\r]*/m', '', $output);

        $output = trim($output);

        if ($output) {
            static::$output .= $output . PHP_EOL;

            static::$climate->out($output);
        }
    }

    /**
     * Print debug info.
     *
     * @param string $info
     *
     * @return void
     */
    protected function printDebug($info)
    {
        $info = trim($info);

        if ($info && static::$config['verbose_enabled']) {
            static::$climate->whisper($info);
        }
    }

    /**
     * Collect errors and clean error log.
     *
     * @return string
     */
    protected function collectErrors()
    {
        if (! file_exists(static::$config['error_log'])) {
            return '';
        }

        $errors = file_get_contents(static::$config['error_log']);

        unlink(static::$config['error_log']);

        return $errors;
    }

    /**
     * Execute command and return result code.
     *
     * @return int
     */
    public function __invoke()
    {
        static::$last = null;
        static::$result = null;
        static::$output = '';

        $this->collectErrors();

        if ($this->cmd === PHP_BINARY) {
            $method = 'handlePHP';
        }
        else if ($this->cmd === UAPI_BINARY) {
            $method = 'handleUAPI';
        }
        else {
            $method = 'handle';
        }

        return static::$result = $this->$method($this->parts);
    }

    /**
     * Handle generic command.
     *
     * @param array $parts
     *
     * @return int
     */
    protected function handle($parts)
    {
        $this->printCommand($command = implode(' ', $parts));

        exec($command, $output, $result);

        $this->printOutput(implode(PHP_EOL, $output));

        return $result;
    }

    /**
     * Handle PHP command.
     *
     * @param array $parts
     *
     * @return int
     */
    protected function handlePHP($parts)
    {
        // print command before adding debug
        $this->printCommand(implode(' ', $parts));

        // redirect errors to fetch after execution
        $this->insertParts($parts, 1, [
            '-d',
            'error_log' => static::$config['error_log'],
        ]);

        $this->insertParts($parts, [
            '2>',
            static::$config['error_log'],
        ]);

        // run command, print errors and output
        exec(implode(' ', $parts), $output, $result);

        $this->printDebug($this->collectErrors());

        $this->printOutput(implode(PHP_EOL, $output));

        return $result;
    }

    /**
     * Handle UAPI command.
     *
     * @param array $parts
     *
     * @return int
     */
    protected function handleUAPI($parts)
    {
        // add default arguments, when used as root we need to specify cPanel user
        $this->insertParts($parts, 1, [
            '--output' => 'jsonpretty',
            '--user' => posix_getuid() == 0 ? static::$config['user'] : null,
        ]);

        // obfuscate certificate data for cleaner output
        $this->printCommand(
            preg_replace('/(-----BEGIN\+[^ ]+)/', '***', implode(' ', $parts))
        );

        // redirect errors to fetch after execution
        $this->insertParts($parts, [
            '2>',
            static::$config['error_log'],
        ]);

        // run command and parse response to determine result code and output
        $response = shell_exec(implode(' ', $parts));

        $this->printDebug($this->collectErrors());
        $this->printDebug('UAPI response: ' . $response);

        if (! $response = json_decode($response, true)) {
            $this->printOutput('The UAPI call did not return a valid response.');
            return EX_UNKNOWN_ERROR;
        }

        $messages = convertQuotes(implode(PHP_EOL, array_merge(
            $response['result']['errors'] ?: [],
            $response['result']['messages'] ?: []
        )));

        $this->printOutput($messages ?: 'The UAPI call failed for an unknown reason.');

        if (! $response['result']['status']) {
            return EX_UAPI_CALL_FAILED;
        }

        return EX_SUCCESS;
    }
}
