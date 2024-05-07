<?php
namespace phasync;

interface SelectableInterface {
    /**
     * Returns `true` if the object will block. This is used to provide
     * polling of multiple objects efficiently for the `phasync::select()`
     * function.
     * 
     * @return bool 
     */
    public function willBlock(): bool;
}