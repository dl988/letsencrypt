<?php 

namespace Imbrish\LetsEncrypt;

class Command {
    /**
     * Command aliases.
     * 
     * @var array
     */
    public static $aliases = [];

    /**
     * Default arguments.
     * 
     * @var array
     */
    public static $defaults = [];

    /**
     * Result of last execution.
     * 
     * @var int
     */
    public static $result;

    /**
     * Output of last execution.
     * 
     * @var string
     */
    public static $output;

    /**
     * Last executed command.
     * 
     * @var string
     */
    public static $last;

    /**
     * Flat array of commands parts.
     * 
     * @var array
     */
    protected $parts = [];

    /**
     * Execute command.
     *
     * @param string $cmd
     * @param array $args
     *
     * @return mixed
     */
    public static function exec($cmd, $args = [])
    {
        $command = new static($cmd, $args);

        return $command();
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
        // initialize command from alias or as given
        $this->parts = [
            PHP_BINARY,
            array_key_exists($cmd, static::$aliases) ? static::$aliases[$cmd] : $cmd,
        ];

        // attach the default arguments
        if (array_key_exists($cmd, static::$defaults)) {
            foreach (static::$defaults[$cmd] as $key => $value) {
                if (! is_int($key)) {
                    $args[$key] = $value;
                }
                else if (! in_array($value, $args)) {
                    $args[] = $value;
                }
            }
        }

        // convert arguments into command parts
        foreach ($args as $key => $value) {
            if (! is_int($key)) {
                $this->parts[] = $key;
            }

            $this->parts[] = $value;
        }
    }

    /**
     * Execute command.
     *
     * @return mixed
     */
    public function __invoke()
    {
        $parts = array_map('escapeshellarg', $this->parts);

        // save and show what command is executed
        static::$last = implode(' ', $parts);

        echo static::$last . PHP_EOL;

        // temporary redirect error log to fetch its output after script execution
        $log_path = __DIR__ . '/../logs.txt';

        array_splice($parts, 1, 0, '-d error_log=' . escapeshellarg($log_path));

        // tunnel error output to standard output
        $parts[] = '2>&1';

        exec(implode(' ', $parts), $output, $code);

        // get and erase temporary error log
        if (file_exists($log_path)) {
            $error_log = file_get_contents($log_path);

            // remove dates from the logged errors
            $error_log = preg_replace('/^\[.+?\] /m', '', $error_log);

            unlink($log_path);
        }
        else {
            $error_log = null;
        }

        // save and show command output together with collected error logs
        static::$output = implode(PHP_EOL, $output) . (trim($error_log) ? $error_log : '');
        static::$output = preg_replace('/^[\t ]+/', '', trim(static::$output));

        if (static::$output) {
            echo static::$output . PHP_EOL;
        }

        // save and return last result code
        static::$result = $code;

        return $code;
    }
}
