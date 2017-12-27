<?php 

namespace NorwegianZipCodes\Events;

use Illuminate\Support\Collection;
use NorwegianZipCodes\Models\ZipCode;

/**
 * @package NorwegianZipCodes\Events
 */
class ZipCodesToDeleteFound {

    protected $zipCodes;

	public function __construct(Collection $zipCode) {
	    $this->zipCodes = $zipCodes;
	}

    /**
     * @return Collection
     */
    public function getZipCodes(): Collection
    {
        return $this->zipCodes;
    }
}
