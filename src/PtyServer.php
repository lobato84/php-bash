<?php
namespace App;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;

class PtyServer implements MessageComponentInterface
{
    /** @var LoopInterface */
    private $loop;

    /** @var array<string,array> */
    private $clients = [];

    /** @var string */
    private $shell;

    /** @var int */
    private $timeout;

    /** @var int */
    private $nice;

    public function __construct(LoopInterface $loop, $shell = '/bin/bash', $timeout = 0, $nice = 0)
    {
        $this->loop    = $loop;
        $this->shell   = $shell;
        $this->timeout = (int)$timeout;
        $this->nice    = (int)$nice;

        if (!$this->isWindows()) {
            // Aviso suave si no está `script` en rutas típicas
            $hasScript = is_file('/usr/bin/script') || is_file('/bin/script') || is_file('/usr/local/bin/script');
            if (!$hasScript) {
                @fwrite(STDERR, "AVISO: No se encuentra 'script'. Instálalo (util-linux/bsdutils).\n");
            }
        }
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $id  = spl_object_hash($conn);
        $cmd = $this->buildPtyCommand();

        $desc = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $proc = @proc_open($cmd, $desc, $pipes, null, null);
        if (!is_resource($proc)) {
            $conn->send(json_encode(['type' => 'notice', 'text' => "No se pudo iniciar PTY"]));
            $conn->close();
            return;
        }

        foreach ([0,1,2] as $i) {
            if (isset($pipes[$i]) && is_resource($pipes[$i])) {
                stream_set_blocking($pipes[$i], false);
            }
        }

        $this->clients[$id] = [
            'conn'        => $conn,
            'proc'        => $proc,
            'pipes'       => $pipes,
            'stdin'       => $pipes[0],
            'stdout'      => $pipes[1],
            'stderr'      => $pipes[2],
            'cols'        => 120,
            'rows'        => 30,
            'timer'       => null,
        ];

        // Registrar streams en el loop (guardaremos refs para quitarlos luego)
        $this->loop->addReadStream($pipes[1], function () use ($id) {
            if (!isset($this->clients[$id])) return;
            $stdout = $this->clients[$id]['stdout'];
            $conn   = $this->clients[$id]['conn'];
            if (!is_resource($stdout)) return;
            $out = @fread($stdout, 8192);
            if ($out === '' || $out === false || $out === null) return;
            $conn->send($out);
        });

        $this->loop->addReadStream($pipes[2], function () use ($id) {
            if (!isset($this->clients[$id])) return;
            $stderr = $this->clients[$id]['stderr'];
            $conn   = $this->clients[$id]['conn'];
            if (!is_resource($stderr)) return;
            $err = @fread($stderr, 4096);
            if ($err === '' || $err === false || $err === null) return;
            $conn->send("\033[31m{$err}\033[0m");
        });

        // Timer para detectar fin del proceso
        $timer = $this->loop->addPeriodicTimer(1.0, function () use ($id) {
            if (!isset($this->clients[$id])) {
                return;
            }
            if (!$this->isProcAlive($id)) {
                $conn = $this->clients[$id]['conn'];
                $conn->send(json_encode(['type' => 'notice', 'text' => "[PTY terminado]"]));
                $conn->close();
                $this->cleanupClient($id);
            }
        });

        $this->clients[$id]['timer'] = $timer;
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $id = spl_object_hash($from);
        if (!isset($this->clients[$id])) return;

        // ¿JSON de control (resize)?
        $isJson = false;
        if (is_string($msg)) {
            $t = ltrim($msg);
            if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) $isJson = true;
        }

        if ($isJson) {
            $data = json_decode($msg, true);
            if (is_array($data) && ($data['type'] ?? '') === 'resize') {
                $cols = max(20, (int)($data['cols'] ?? 120));
                $rows = max(5,  (int)($data['rows'] ?? 30));
                $this->clients[$id]['cols'] = $cols;
                $this->clients[$id]['rows'] = $rows;

                if ($this->isProcAlive($id) && $this->isWritable($id)) {
                    // stty para que apps TUI ajusten el tamaño
                    @fwrite($this->clients[$id]['stdin'], "stty cols {$cols} rows {$rows}\n");
                }
                return;
            }
        }

        // Entrada del usuario → PTY
        if ($this->isProcAlive($id) && $this->isWritable($id)) {
            @fwrite($this->clients[$id]['stdin'], $msg);
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $id = spl_object_hash($conn);
        $this->cleanupClient($id);
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $conn->send(json_encode(['type' => 'notice', 'text' => "Error servidor PTY: ".$e->getMessage()]));
        $conn->close();
    }

    /* ================== Helpers ================== */

    private function isProcAlive($id)
    {
        if (!isset($this->clients[$id])) return false;
        $st = @proc_get_status($this->clients[$id]['proc']);
        return $st && isset($st['running']) && $st['running'] === true;
    }

    private function isWritable($id)
    {
        return isset($this->clients[$id]['stdin']) && is_resource($this->clients[$id]['stdin']);
    }

    private function cleanupClient($id)
    {
        if (!isset($this->clients[$id])) return;

        // 1) Cancelar timer
        if (isset($this->clients[$id]['timer']) && $this->clients[$id]['timer'] !== null) {
            $this->loop->cancelTimer($this->clients[$id]['timer']);
        }

        // 2) Quitar read streams del loop ANTES de cerrar los pipes
        $pipes = $this->clients[$id]['pipes'] ?? [];
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            $this->loop->removeReadStream($pipes[1]);
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            $this->loop->removeReadStream($pipes[2]);
        }

        // 3) Cerrar pipes
        foreach ([0,1,2] as $i) {
            if (isset($pipes[$i]) && is_resource($pipes[$i])) {
                @fclose($pipes[$i]);
            }
        }

        // 4) Terminar proceso
        if (isset($this->clients[$id]['proc']) && is_resource($this->clients[$id]['proc'])) {
            @proc_terminate($this->clients[$id]['proc']);
            @proc_close($this->clients[$id]['proc']);
        }

        unset($this->clients[$id]);
    }

    private function buildPtyCommand()
    {
        // Preferimos `-c` para que `script` ejecute directamente el shell login
        // y evitemos el problema del `-l` como opción de script.
        //   script -qfc 'bash -l' /dev/null
        if ($this->isWindows()) {
            // WSL: asegúrate de que /usr/bin/script existe en la distro por defecto
            $cmd = 'wsl.exe -e /usr/bin/script -qfc ' . escapeshellarg($this->shell . ' -l') . ' /dev/null';
        } else {
            $script = '/usr/bin/script';
            if (!is_file($script)) {
                if (is_file('/bin/script')) $script = '/bin/script';
                elseif (is_file('/usr/local/bin/script')) $script = '/usr/local/bin/script';
            }
            $cmd = $script . ' -qfc ' . escapeshellarg($this->shell . ' -l') . ' /dev/null';
            // Si tu `script` no soporta -c, cambia a:
            // $cmd = $script . ' -q /dev/null -- ' . escapeshellarg($this->shell) . ' -l';
        }

        return $this->wrapWithTimeoutNice($cmd);
    }

    private function wrapWithTimeoutNice($cmd)
    {
        $prefix = '';
        if ($this->nice !== 0) {
            $prefix .= 'nice -n ' . (int)$this->nice . ' ';
        }
        if ($this->timeout > 0) {
            $prefix = 'timeout ' . (int)$this->timeout . ' ' . $prefix;
        }
        return $prefix . $cmd;
    }

    private function isWindows()
    {
        return stripos(PHP_OS_FAMILY, 'Windows') === 0;
    }
}
