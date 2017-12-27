<?php 

namespace NorwegianZipCodes\Events;

use Illuminate\Support\Collection;
use NorwegianZipCodes\Models\ZipCode;

/**
 * @package NorwegianZipCodes\Events
 */
class MunicipalitiesToDeleteFound {

    protected $municipalities;

	public function __construct(Collection $municipalities) {
	    $this->municipalities = $municipalities;
	}

    /**
     * @return Collection
     */
    public function getMunicipalities(): Collection
    {
        return $this->municipalities;
    }
}
