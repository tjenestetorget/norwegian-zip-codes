<?php namespace NorwegianZipCodes\Commands;

use Goutte\Client;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use NorwegianZipCodes\Events\MunicipalityCountyUpdated;
use NorwegianZipCodes\Events\ZipCodeMunicipalityUpdated;
use NorwegianZipCodes\Events\ZipCodesUpdated;
use NorwegianZipCodes\Lib\RemoteZipCodeFileParser;
use NorwegianZipCodes\Models\County;
use NorwegianZipCodes\Models\Municipality;
use NorwegianZipCodes\Models\ZipCode;

class UpdateZipCodesCommand extends Command {

	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $name = 'zip_codes:update';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Updates the norwegian zip codes and municipalities with data from the official authority';

	/**
	 *
	 * @var Collection
	 */
	protected $counties;

	protected $added = 0;

	protected $changed = 0;

	protected $changedMunicipalities = [];

	protected $changedZipCodes = [];

	protected $dispatcher;

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function fire(Dispatcher $dispatcher)
	{
	    $this->dispatcher = $dispatcher;
	    
		$dispatcher->fire('zip_codes.update.starting');

		$this->counties = County::all();
		$url            = $this->getRemoteZipCodeFile();

		$parser = app(RemoteZipCodeFileParser::class);
        $zipCodeIds = [];
        $municipalityIds = [];
        $countyIds = [];

		$parser->parse($url, function(RemoteZipCodeObject $object) use(&$zipCodeIds, &$municipalityIds, &$countyIds) {
			$municipality = $this->updateMunicipality($object->municipality_id, $object->municipality_name);
			$this->updateZipCode($municipality, $object->id, $object->name);

            $zipCodeIds[] = $object->id;
            $municipalityIds[] = $municipality->id;
            $countyIds[] = $object->municipality_id;
		});

		$dispatcher->fire(new ZipCodesUpdated($this->added, $this->changed));

		$municipalitiesToDelete = Municipality::whereNotIn($municipalityIds)->get();
		$countiesToDelete = County::whereNotIn($countyIds)->get();

//        dump('Updated: '.$this->changed);
//        dump('Added: '.$this->added);
//        dump('Counties to delete: ', $countiesToDelete->pluck('id')->toArray());
//        dump('Municipalities to delete: ', $municipalitiesToDelete->pluck('id')->toArray());
//        dump('Zip codes to delete: ', $zipCodeIds->pluck('id')->toArray());
//        dump('Municipalities with changed counties: ', $this->changedMunicipalities);
//        dump('Zip codes with changed municipality: ', $this->changedZipCodes);

        $report = "Updated: " . $this->changed . "\n" .
            "Added: ".$this->added ."\n" .
            "Counties to delete: " . implode(', ', $countiesToDelete->pluck('id')->toArray())."\n".
            "Municipalities to delete: " . implode(', ', $municipalitiesToDelete->pluck('id')->toArray())."\n".
            "Zip codes to delete: " . implode(', ', $zipCodeIds->pluck('id')->toArray())."\n".
            "Municipalities with changed counties: " . json_encode($this->changedMunicipalities)."\n".
            "Zip codes with changed municipality: " . json_encode($this->changedZipCodes);

        dump($report);

        \Log::debug($report);
	}

	protected function getCounty($municipality_id) {
		$county_id = substr(str_pad($municipality_id, 4, '0', STR_PAD_LEFT), 0, 2);
		/* @var County $county */
		$county = $this->counties->find($county_id);

		return $county;
	}

	protected function updateMunicipality($id, $name) {
		$county = $this->getCounty($id);
		$municipality = Municipality::find($id);

		if(is_null($municipality)) {
			$municipality = new Municipality(['id' => $id, 'name' => $name]);
			$county->municipalities()->save($municipality);
			$this->added++;
		}
		else {
			$municipality->setAttribute('name', $name);

            if($municipality->county_id != $county->id) {
                $oldCountyId = $municipality->county_id;
                $municipality->setAttribute('country_id', $county->id);
                $this->dispatcher->dispatch(new MunicipalityCountyUpdated($municipality, $oldCountyId));
                $this->changedMunicipalities[$municipality->id] = [$oldCountyId, $county->id];
            }

			$this->checkDirty($municipality);
			$municipality->save();
		}

		return $municipality;
	}

	protected function checkDirty(Model $model) {
		if($model->isDirty()) {
			$this->changed++;
		}
	}

	protected function updateZipCode(Municipality $municipality, $id, $name) {

		$zipCode = ZipCode::find($id);

		if(is_null($zipCode)) {
			$zipCode = new ZipCode(['id' => $id, 'name' => $name]);
			$municipality->zip_codes()->save($zipCode);
			$this->added++;
		}
		else {
			$zipCode->setAttribute('name', $name);
			
			if($municipality->id != $zipCode->municipality_id) {
                $oldMunicipalityId = $zipCode->municipality_id;
                $zipCode->setAttribute('municipality_id', $municipality->id);

                $this->changedZipCodes[$zipCode->id] = [$oldMunicipalityId, $municipality->id];
			    $this->dispatcher->dispatch(new ZipCodeMunicipalityUpdated($zipCode, $oldMunicipalityId));
            }
			
			$this->checkDirty($zipCode);
			$zipCode->save();
		}
	}

    protected function getRemoteZipCodeFile() {
        $client = new Client();
        $crawler = $client->request('GET', 'http://www.bring.no/hele-bring/produkter-og-tjenester/brev-og-postreklame/andre-tjenester/postnummertabeller');
        $link = $crawler->filterXPath('//td[text() = "Postnummer i rekkefølge"]/following-sibling::td/a[contains(., "Tab")]');
        $url = 'http://www.bring.no/'.$link->attr('href');

        return $url;
    }
}
