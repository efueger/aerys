<?php

namespace Aerys;

use Amp\Promise;

interface SessionDriver {
    /**
     * @return \Amp\Promise resolving to a \stdClass with current session data
     */
    public function open(string $id): Promise;

    /**
     * @param \stdClass $data to store
     * @return \Amp\Promise resolving after success
     */
    public function save(string $id, \stdClass $data): Promise;

    /**
     * Create a lock so that nobody can read nor write the session data form now on
     * @return \Amp\Promise resolving when a lock could be created
     */
    public function lock(string $id): Promise;
    /**
     * Unlocks the session so that session can be read and written to again
     * @return \Amp\Promise resolving after success
     */
    public function unlock(string $id): Promise;

    /**
     * Destroys the session
     * @return \Amp\Promise resolving after success
     */
    public function destroy(string $id): Promise;
}