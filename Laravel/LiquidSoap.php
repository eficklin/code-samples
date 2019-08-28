<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class LiquidSoap extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stream:liquidsoap {ls_command}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Issue command to the liquidsoap daemon';

    /**
     * Allowed liquidsoap server commands
     * @var array
     */
    protected $commands = [
        "exit",
        "help",
        "list",
        "output(dot)shoutcast.autostart",
        "output(dot)shoutcast.metadata",
        "output(dot)shoutcast.remaining",
        "output(dot)shoutcast.skip",
        "output(dot)shoutcast.start",
        "output(dot)shoutcast.status",
        "output(dot)shoutcast.stop",
        "quit",
        "request.alive",
        "request.all",
        "request.metadata",
        "request.on_air",
        "request.resolving",
        "request.trace",
        "uptime",
        "var.get",
        "var.list",
        "var.set",
        "version"
    ];

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $command = $this->argument('ls_command');

        if (!in_array($command, $this->commands)) {
            $this->error('Command not recognized.');
            return;
        }

        if (!file_exists(env('LIQUIDSOAP_SOCKET'))) {
            $this->error('Liquidsoap not running');
            return;
        }
            
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);

        if ($socket === false) {
            $error_code = socket_last_error();
            $this->error(socket_strerror($error_code));
            return;
        }

        $connected = socket_connect($socket, env('LIQUIDSOAP_SOCKET'));

        if (!$connected) {
            $error_code = socket_last_error($socket);
            $this->error(socket_strerror($error_code));
            return;
        }

        $command .= "\nquit\n";
        $bytes_written = socket_write($socket, $command, strlen($command));

        if ($bytes_written === false) {
            $error_code = socket_last_error($socket);
            $this->error(socket_strerror($error_code));
            return;
        }

        $out = [];

        while ($out[] = socket_read($socket, 2048));

        if (count($out) > 1) {
            $out = implode("", $out);
        } else {
            $out = $out[0];
        }

        $lines = explode("\r\n", $out);

        foreach ($lines as $line) {
            if (starts_with($line, 'END'))
                break;
            $this->line($line);
        }
    }
}
