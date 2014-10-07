<?php
namespace GuzzleHttp\Ring\Future;

use GuzzleHttp\Ring\Exception\CancelledException;
use GuzzleHttp\Ring\Exception\CancelledFutureAccessException;
use GuzzleHttp\Ring\Exception\RingException;
use React\Promise\PromiseInterface;

/**
 * Implements common future functionality built on top of promises.
 */
trait BaseFutureTrait
{
    /** @var callable */
    private $waitfn;

    /** @var callable */
    private $cancelfn;

    /** @var PromiseInterface */
    private $promise;

    /** @var \Exception */
    private $error;
    private $result;

    private $isRealized = false;

    /**
     * @param PromiseInterface $promise Promise to shadow with the future. Only
     *                                  supply if the promise is not owned
     *                                  by the deferred value.
     * @param callable         $wait    Function that blocks until the deferred
     *                                  computation has been resolved. This
     *                                  function MUST resolve the deferred value
     *                                  associated with the supplied promise.
     * @param callable         $cancel  If possible and reasonable, provide a
     *                                  function that can be used to cancel the
     *                                  future from completing. The cancel
     *                                  function should return true on success
     *                                  and false on failure.
     */
    public function __construct(
        PromiseInterface $promise,
        callable $wait = null,
        callable $cancel = null
    ) {
        $this->promise = $promise;
        $this->waitfn = $wait;
        $this->cancelfn = $cancel;
    }

    public function wait()
    {
        if (!$this->isRealized) {
            $this->addShadow();
            if (!$this->isRealized && $this->waitfn) {
                $this->invokeWait();
            }
            if (!$this->isRealized) {
                $this->error = new RingException('Waiting did not resolve future');
            }
        }

        if ($this->error) {
            throw $this->error;
        }

        return $this->result;
    }

    public function then(
        callable $onFulfilled = null,
        callable $onRejected = null,
        callable $onProgress = null
    ) {
        return $this->promise->then($onFulfilled, $onRejected, $onProgress);
    }

    public function cancel()
    {
        // Cannot cancel a cancelled or completed future.
        if ($this->isRealized) {
            return false;
        }

        $cancelfn = $this->cancelfn;
        $this->markCancelled(new CancelledFutureAccessException());

        return $cancelfn ? $cancelfn($this) : false;
    }

    /**
     * Adds a then() shadow to the promise to get the resolved value or error.
     */
    private function addShadow()
    {
        // Get the result and error when the promise is resolved. Note that
        // calling this function might trigger the resolution immediately.
        $this->promise->then(
            function ($value) {
                $this->isRealized = true;
                $this->result = $value;
                $this->waitfn = $this->cancelfn = null;
            },
            function ($error) {
                $this->isRealized = true;
                $this->error = $error;
                $this->waitfn = $this->cancelfn = null;
                if ($error instanceof CancelledException) {
                    $this->markCancelled($error);
                }
            }
        );
    }

    private function invokeWait()
    {
        try {
            $wait = $this->waitfn;
            $this->waitfn = null;
            $wait();
        } catch (CancelledException $e) {
            // Throwing this exception adds an error and marks the
            // future as cancelled.
            $this->markCancelled($e);
        } catch (\Exception $e) {
            // Defer can throw to reject.
            $this->error = $e;
            $this->isRealized = true;
        }
    }

    private function markCancelled(CancelledException $e)
    {
        $this->waitfn = $this->cancelfn = null;
        $this->isRealized = true;
        $this->error = $e;
    }
}