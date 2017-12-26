<?php 

namespace NorwegianZipCodes\Events;

use NorwegianZipCodes\Models\ZipCode;

/**
 * @package NorwegianZipCodes\Events
 */
class ZipCodeMunicipalityUpdated {

    protected $zipCode;
    protected $oldMunicipalityId;

	public function __construct(ZipCode $zipCode, string $oldMunicipalityId) {
	    $this->zipCode = $zipCode;
	    $this->oldMunicipalityId = $oldMunicipalityId;
	}
}
