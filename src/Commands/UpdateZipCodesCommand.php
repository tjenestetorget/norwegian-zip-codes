<?php namespace NorwegianZipCodes\Commands;

use Goutte\Client;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use NorwegianZipCodes\Events\MunicipalitiesToDeleteFound;
use NorwegianZipCodes\Events\MunicipalityCountyUpdated;
use NorwegianZipCodes\Events\ZipCodeMunicipalityUpdated;
use NorwegianZipCodes\Events\ZipCodesToDeleteFound;
use NorwegianZipCodes\Events\ZipCodesUpdated;
use NorwegianZipCodes\Lib\RemoteZipCodeFileParser;
use NorwegianZipCodes\Models\County;
use NorwegianZipCodes\Models\Municipality;
use NorwegianZipCodes\Models\ZipCode;
use Symfony\Component\Console\Input\InputOption;

class UpdateZipCodesCommand extends Command {

    const OPTION_OSLO_MUNICIPALITIES = 'oslo_municipalities';

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
    public function handle(Dispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
        $dispatcher->dispatch('zip_codes.update.starting');

        $this->patchCounties();
        $this->counties = County::all();
        $url = $this->getRemoteZipCodeFile();

        $parser = app(RemoteZipCodeFileParser::class);
        $zipCodeIds = [];
        $municipalityIds = [];

        if($this->useOlsoMunicipalities()) {
            $this->addOsloMunicipalities();
        }

        $parser->parse($url, function(RemoteZipCodeObject $object) use(&$zipCodeIds, &$municipalityIds) {
            $municipality = $this->updateMunicipality($object->municipality_id, $object->municipality_name);
            $this->updateZipCode($municipality, $object->id, $object->name);

            $zipCodeIds[] = $object->id;
            $municipalityIds[] = $municipality->id;
        });

        if($this->useOlsoMunicipalities()) {
            $municipalityIds = array_merge($municipalityIds, array_keys($this->getOsloMunicipalities()));
        }

        $dispatcher->dispatch(new ZipCodesUpdated($this->added, $this->changed));

        $municipalitiesToDelete = Municipality::whereNotIn('id', $municipalityIds)->get();
        $zipCodesToDelete = ZipCode::whereNotIn('id', $zipCodeIds)->get();

        if($municipalitiesToDelete->count()) {
            $dispatcher->dispatch(new MunicipalitiesToDeleteFound($municipalitiesToDelete));
        }

        if($zipCodesToDelete->count()) {
            $dispatcher->dispatch(new ZipCodesToDeleteFound($zipCodesToDelete));
        }

        $this->deleteDeprecatedCounties();

        $report = "Updated: " . $this->changed . "\n" .
            "Added: ".$this->added ."\n" .
            "Municipalities to delete: " . implode(', ', $municipalitiesToDelete->pluck('id')->toArray())."\n".
            "Zip codes to delete: " . implode(', ', $zipCodesToDelete->pluck('id')->toArray())."\n".
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

        if($this->useOlsoMunicipalities() && $county->id == '03') {
            return $municipality;
        }

        if(is_null($county)) {
            dd($id, $name);
        }

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

        if($this->useOlsoMunicipalities()) {
            $osloMunicipality = $this->getOsloMunicipalityByZip($id);

            if($osloMunicipality) {
                $municipality = $osloMunicipality;
            }
        }

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

    protected function getOsloZipCodes() {
        return [
            '0301' => array('0001', '0010', '0015', '0018', '0022', '0025', '0026', '0028', '0030', '0031', '0032', '0033', '0034', '0037', '0048', '0050', '0051', '0055', '0080', '0081', '0101', '0102', '0103', '0104', '0105', '0106', '0107', '0109', '0110', '0111', '0112', '0113', '0114', '0115', '0116', '0117', '0118', '0119', '0120', '0121', '0122', '0123', '0124', '0125', '0126', '0127', '0128', '0151', '0152', '0153', '0154', '0155', '0157', '0158', '0159', '0160', '0161', '0162', '0164', '0166', '0184', '0185', '0251'),
            '0302' => array('0133', '0134', '0135', '0150', '0187', '0188', '0190', '0191', '0192', '0195', '0196', '0561', '0577', '0578', '0601', '0602', '0603', '0604', '0605', '0606', '0607', '0608', '0609', '0626', '0630', '0640', '0650', '0651', '0652', '0653', '0654', '0655', '0656', '0657', '0658', '0659', '0660', '0661', '0662', '0663'),
            '0303' => array('0175', '0182', '0186', '0501', '0502', '0503', '0504', '0505', '0506', '0550', '0551', '0552', '0553', '0554', '0556', '0557', '0558', '0559', '0560', '0562', '0563', '0564', '0565', '0566', '0567', '0568', '0569', '0570', '0571', '0572', '0573', '0574', '0575', '0576', '0579'),
            '0304' => array('0040', '0041', '0042', '0043', '0406', '0412', '0413', '0415', '0445', '0457', '0458', '0459', '0460', '0461', '0462', '0463', '0464', '0465', '0467', '0468', '0469', '0470', '0472', '0473', '0474', '0475', '0476', '0477', '0478', '0479', '0480', '0481', '0482', '0483', '0485', '0555'),
            '0305' => array('0129', '0130', '0131', '0136', '0165', '0168', '0169', '0170', '0171', '0172', '0173', '0174', '0176', '0177', '0178', '0179', '0180', '0181', '0183', '0340', '0358', '0359', '0360', '0361', '0407', '0408', '0440', '0450', '0451', '0452', '0454', '0455', '0456', '0850'),
            '0306' => array('0021', '0167', '0201', '0202', '0203', '0204', '0207', '0208', '0211', '0230', '0240', '0242', '0244', '0246', '0250', '0252', '0253', '0254', '0255', '0256', '0257', '0258', '0259', '0260', '0262', '0263', '0264', '0265', '0266', '0267', '0268', '0270', '0271', '0272', '0273', '0286', '0287', '0301', '0302', '0303', '0304', '0305', '0306', '0307', '0308', '0323', '0330', '0350', '0351', '0352', '0353', '0354', '0355', '0356', '0357', '0362', '0363', '0364', '0365', '0366', '0367', '0368', '0369'),
            '0307' => array('0212', '0213', '0214', '0215', '0216', '0218', '0247', '0274', '0275', '0276', '0277', '0278', '0279', '0280', '0281', '0282', '0283', '0284', '0310', '0311', '0377', '0379', '0380', '0381', '0382', '0383', '0750'),
            '0308' => array('0016', '0309', '0319', '0370', '0371', '0373', '0374', '0375', '0376', '0378', '0701', '0702', '0705', '0710', '0712', '0751', '0752', '0753', '0754', '0755', '0756', '0757', '0758', '0760', '0763', '0764', '0765', '0766', '0767', '0768', '0770', '0771', '0772', '0773', '0774', '0775', '0776', '0777', '0778', '0779', '0781', '0782', '0783', '0784', '0785', '0786', '0787', '0788', '0789', '0790', '0791'),
            '0309' => array('0027', '0313', '0314', '0315', '0316', '0317', '0318', '0349', '0372', '0401', '0402', '0403', '0404', '0405', '0409', '0410', '0411', '0421', '0422', '0423', '0424', '0426', '0441', '0442', '0484', '0486', '0487', '0488', '0489', '0490', '0491', '0492', '0493', '0494', '0495', '0496', '0587', '0588', '0801', '0805', '0806', '0807', '0840', '0851', '0852', '0853', '0854', '0855', '0856', '0857', '0858', '0860', '0861', '0862', '0863', '0864', '0870', '0871', '0872', '0873', '0874', '0875', '0876', '0877', '0880', '0881', '0882', '0883', '0884'),
            '0310' => array('0508', '0509', '0510', '0511', '0512', '0513', '0514', '0515', '0516', '0517', '0518', '0520', '0540', '0580', '0581', '0582', '0583', '0584', '0585', '0586', '0589', '0590', '0591', '0592', '0593', '0594', '0595', '0596', '0597', '0598', '0950'),

            '0311' => array('0901', '0902', '0903', '0904', '0905', '0907', '0908', '0951', '0952', '0953', '0954', '0955', '0956', '0957', '0958', '0959', '0960', '0962', '0963', '0964', '0970', '0971', '0972', '0973', '0975', '0976'),
            '0312' => array('0045', '0046', '0047', '0913', '0915', '0968', '0969', '0977', '0978', '0979', '0980', '0981', '0982', '0983', '0984', '0985', '0986', '0987', '0988', '1005', '1055', '1084', '1086', '1087', '1088', '1089'),
            '0313' => array('0024', '0613', '0614', '0616', '0617', '0618', '0623', '0664', '0665', '0666', '0668', '0669', '0670', '0672', '0673', '0674', '0675', '0676', '1001', '1003', '1006', '1007', '1008', '1009', '1011', '1051', '1052', '1053', '1054', '1056', '1061', '1062', '1063', '1064', '1065', '1067', '1068', '1069', '1071', '1081', '1083', '1109', '1112'),
            '0314' => array('0611', '0612', '0619', '0620', '0621', '0622', '0624', '0667', '0671', '0678', '0679', '0680', '0681', '0682', '0683', '0684', '0685', '0686', '0687', '0688', '0689', '0690', '0691', '0692', '0693', '0694', '1187', '1188', '1189'),
            '0315' => array('0137', '0138', '0139', '0193', '0198', '0677', '1101', '1150', '1151', '1152', '1153', '1154', '1155', '1156', '1157', '1158', '1160', '1161', '1162', '1163', '1164', '1165', '1166', '1167', '1168', '1169', '1170', '1172', '1176', '1177', '1178', '1179', '1181', '1182', '1184', '1185'),
            '0316' => array('1201', '1202', '1203', '1204', '1205', '1207', '1214', '1215', '1250', '1251', '1252', '1253', '1254', '1255', '1256', '1257', '1258', '1259', '1262', '1263', '1266', '1270', '1271', '1272', '1273', '1274', '1275', '1277', '1278', '1279', '1281', '1283', '1284', '1285', '1286', '1290', '1291', '1294', '1295'),
            '0317' => array('0759', '0890', '0891')
        ];
    }

    protected function getOsloMunicipalityByZip($zipCode)
    {
        $zipCodes = $this->getOsloZipCodes();

        foreach ($zipCodes as $municipalityId => $zipCodesGroup) {
            if(in_array($zipCode, $zipCodesGroup)) {
                return County::find($municipalityId);
            }
        }

        return null;
    }

    protected function getOsloMunicipalities()
    {
        return [
            '0301' => 'Sentrum',
            '0302' => 'Gamle Oslo',
            '0303' => 'Grunerløkka',
            '0304' => 'Sagene',
            '0305' => 'St. Hanshaugen',
            '0306' => 'Frogner',
            '0307' => 'Ullern',
            '0308' => 'Vestre Aker',
            '0309' => 'Nordre Aker',
            '0310' => 'Bjerke',
            '0311' => 'Grorud',
            '0312' => 'Stovner',
            '0313' => 'Alna',
            '0314' => 'Østensjø',
            '0315' => 'Nordstrand',
            '0316' => 'Søndre Nordstrand',
            '0317' => 'Marka',
        ];
    }

    /**
     * Get the console command options.
     * @return array
     */
    protected function getOptions()
    {
        return [
            [self::OPTION_OSLO_MUNICIPALITIES, null, InputOption::VALUE_NONE, 'Use Oslo municipalities',],
        ];
    }

    protected function addOsloMunicipalities()
    {
        $osloCounty = County::find('03');
        $municipalitiesData = $this->getOsloMunicipalities();
        $municipality = Municipality::find('0302');

        if(!$municipality) {
            $municipality = Municipality::find(key($municipalitiesData));
            $municipality->name = array_shift($municipalitiesData);
            $municipality->save();

            foreach ($municipalitiesData as $id => $name) {
                $municipality = new Municipality(['id' => $id, 'name' => $name]);
                $osloCounty->municipalities()->save($municipality);
            }
        }
    }

    protected function useOlsoMunicipalities()
    {
        return $this->option(self::OPTION_OSLO_MUNICIPALITIES);
    }

    protected function patchCounties()
    {
        if(County::find('50')) {
            return;
        }

        $county = new County([
            'id' => '50',
            'name' => 'Trøndelag'
        ]);

        $county->save();

        Municipality::whereIn('county_id', ['16', '17'])->update([
            'county_id' => '50'
        ]);
    }

    protected function deleteDeprecatedCounties()
    {
        County::whereIn('id', ['16', '17'])->delete();
    }
}
