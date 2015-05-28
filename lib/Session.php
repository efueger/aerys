<?php

namespace Aerys;

use Amp\{
    Promise,
    function pipe
};

class Session implements \ArrayAccess {
    const CONFIG = [
        "name" => "AerysSessionId",
        "ttl" => -1,
    ];

    private $request;
    private $driver;
    private $id;
    private $config;
    private $data;
    private $writable = false;
    private $readPipe;
    private $defaultPipe;

    public function  __construct(Request $request) {
        $this->readPipe = function(array $data) {
            $this->data = $data;
            return $this;
        };
        $this->defaultPipe = function() {
            return $this;
        };

        $this->request = $request;
        $config = $request->getLocalVar("aerys.session.config");
        $this->driver = $config["driver"];

        if (!isset($config["cookie_flags"])) {
            $info = $request->getConnectionInfo();
            $config["cookie_flags"] = $info["is_encrypted"] ? ["secure"] : [];
        }

        $config += static::CONFIG;
        $request->setLocalVar("aerys.session.config", $config);

        $this->setId($request->getCookie($config["name"]) ?? $this->generateId());
        $this->setSessionCookie();
    }


    private function generateId() {
        return bin2hex(random_bytes(24));
    }

    private function setId($id) {
        $this->id = $id;
        $this->request->setLocalVar("aerys.session.id", $id);
    }

    /**
     * @param int $ttl sets a ttl, -1 to disable it
     */
    public function setTTL(int $ttl) {
        $config = $this->request->getLocalVar("aerys.session.config");
        $config["ttl"] = $ttl;
        $this->request->setLocalVar("aerys.session.config", $config);
    }

    public function offsetExists($offset) {
        return array_key_exists($offset, $this->data);
    }

    public function offsetGet($offset) {
        if (!array_key_exists($offset, $this->data)) {
            throw new \Exception("Key '$offset' does not exist in session"); // @TODO change to more specific exception
        }

        return $this->data[$offset];
    }

    public function offsetSet($offset, $value) {
        if (!$this->writable) {
            throw new \Exception("Session is not locked, can't write"); // @TODO change to more specific exception
        }
        $this->data[$offset] = $value;
    }

    public function offsetUnset($offset) {
        unset($this->data[$offset]);
    }

    /**
     * Creates a lock and reads the current session data
     * @return \Amp\Promise resolving after success
     */
    public function open(): Promise {
        $this->writable = true;
        return pipe($this->driver->open($this->id), $this->readPipe);
    }

    /**
     * Saves and unlocks a session
     * @return \Amp\Promise resolving after success
     */
    public function save(): Promise {
        $this->writable = false;
        return pipe($this->driver->save($this->id, $this->data), $this->defaultPipe);
    }

    /**
     * Reloads the session contents and locks
     * @return \Amp\Promise resolving after success
     */
    public function read(): Promise {
        return pipe($this->driver->read($this->id), $this->readPipe);
    }

    /**
     * Unlocks the session, reloads data without saving
     * @return \Amp\Promise resolving after success
     */
    public function unlock(): Promise {
        $this->writable = false;
        return pipe($this->driver->unlock(), function() {
            return pipe($this->config["driver"]->read($this->id), $this->readPipe);
        });
    }

    /**
     * Regenerates a session id
     * @return \Amp\Promise resolving after success
     */
    public function regenerate(): Promise {
        $new = $this->generateId();
        $promise = $this->driver->regenerate($this->id, $new);
        $this->setId($new);
        return pipe($promise, $this->defaultPipe);
    }

    /**
     * Destroys the session
     * @return \Amp\Promise resolving after success
     */
    public function destroy(): Promise {
        $promise = $this->driver->destroy($this->id);
        $this->setId($this->generateId());
        $this->data = [];
        return pipe($promise, $this->defaultPipe);
    }

    public function __destruct() {
        $this->save();
    }
}