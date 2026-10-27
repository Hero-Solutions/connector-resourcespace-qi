<?php
// Standalone offline regression tests. Deliberately do not load Composer,
// Symfony, application configuration, .env files, or real database/HTTP clients.
namespace Doctrine\ORM {
    interface EntityManagerInterface {}
    class EntityManager implements EntityManagerInterface
    {
        public array $persisted = [];
        public function __construct(public object $connection, public array $resources = []) {}
        public function getConnection(): object { return $this->connection; }
        public function persist($entity): void { $this->persisted[] = $entity; }
        public function flush(): void {}
        public function remove($entity): void { throw new \RuntimeException('Import registrations must not be deleted'); }
        public function createQueryBuilder(): object
        {
            return new class($this->resources) {
                private \DateTimeInterface $cutoff;
                public function __construct(private array $resources) {}
                public function select(...$args): self { return $this; }
                public function from(...$args): self { return $this; }
                public function where(...$args): self { return $this; }
                public function setParameter($name, $value): self { $this->cutoff = $value; return $this; }
                public function getQuery(): self { return $this; }
                public function getResult(): array
                {
                    return array_values(array_filter($this->resources,
                        fn($resource) => $resource->getImportTimestamp() > $this->cutoff));
                }
            };
        }
    }
}
namespace Symfony\Component\Console\Command { class Command {} }
namespace App\ResourceSpace {
    class ResourceSpace
    {
        public array $imageRequests = [];
        public function getAllImages($id): array
        {
            $this->imageRequests[] = $id;
            return [['size_code' => 'scr', 'extension' => 'jpg', 'url' => 'fixture-only']];
        }
    }
}
namespace App\Util {
    class HttpUtil
    {
        public array $requests = [];
        public array $delta = [];
        public array $objects = [];
        public array $objectResponses = [];
        public function get($url, $username = null, $password = null): string|bool
        {
            $this->requests[] = $url;
            if (preg_match('~/get/object/id/(\d+)/~', $url, $matches)) {
                if (array_key_exists((int) $matches[1], $this->objectResponses)) {
                    return $this->objectResponses[(int) $matches[1]];
                }
                $records = isset($this->objects[(int) $matches[1]]) ? [$this->objects[(int) $matches[1]]] : [];
            } else {
                $records = $this->delta;
            }
            return json_encode((object) ['records' => $records], JSON_THROW_ON_ERROR);
        }
    }
}
namespace {
    require __DIR__ . '/../src/Entity/Resource.php';
    require __DIR__ . '/../src/Qi/Qi.php';
    require __DIR__ . '/../src/Command/ProcessCommand.php';

    use App\Command\ProcessCommand;
    use App\Entity\Resource;
    use App\Qi\Qi;
    use App\ResourceSpace\ResourceSpace;
    use App\Util\HttpUtil;
    use Doctrine\ORM\EntityManager;

    class FixtureConnection
    {
        public array $cache = [];
        public array $staging = [];
        public array $archives = [];
        public array $writes = [];
        public int $closes = 0;
        public bool $failNextWrite = false;
        public function executeQuery(string $sql): object
        {
            if ($sql === 'SELECT metadata FROM qi_object') {
                $rows = array_map(fn($record) => ['metadata' => json_encode($record, JSON_THROW_ON_ERROR)], array_values($this->cache));
            } elseif ($sql === 'SELECT DISTINCT resource_id, object_id FROM unlinked_resource WHERE linked > 0') {
                $rows = array_values(array_filter($this->archives, fn($row) => $row['linked'] > 0));
            } elseif ($sql === 'SELECT 1') {
                $rows = [];
            } else {
                throw new RuntimeException('Unexpected fixture query: ' . $sql);
            }
            return new class($rows) {
                public function __construct(private array $rows) {}
                public function iterateAssociative(): iterable { yield from $this->rows; }
                public function free(): void {}
            };
        }
        public function executeStatement(string $sql, array $params): void
        {
            if ($this->failNextWrite) {
                $this->failNextWrite = false;
                throw new RuntimeException('Simulated idle connection');
            }
            if (!preg_match('/^INSERT INTO (qi_object|qi_object_staging) /', $sql, $matches)) {
                throw new RuntimeException('Unexpected fixture statement: ' . $sql);
            }
            $this->writes[] = $matches[1];
            $record = json_decode($params['metadata'], false, 512, JSON_THROW_ON_ERROR);
            if ($matches[1] === 'qi_object') {
                $this->cache[$params['object_id']] = $record;
            } else {
                $this->staging[$params['object_id']] = $record;
            }
        }
        public function close(): void { $this->closes++; }
    }

    class FixtureQi extends Qi
    {
        public array $metadataWrites = [];
        public int $rsMetadataUpdates = 0;
        public int $matchingAttempts = 0;
        public function __construct(...$arguments)
        {
            if ($arguments !== []) { parent::__construct(...$arguments); }
        }
        public function updateMetadata($image, $resource, $fields, $mapping, $prefix, $addLink, $reindex): void
        {
            $this->metadataWrites[] = ['id' => $image['id'], 'addLink' => $addLink];
        }
        public function updateResourceSpaceData($object, $resource, $id, $fields, $mapping, $fullFields, $url, ResourceSpace $rs): void
        {
            $this->rsMetadataUpdates++;
        }
        public function getMatchingImageToBeLinked($images, $filename, $width, $height, $filesize, $folders): array|null
        {
            $this->matchingAttempts++;
            return parent::getMatchingImageToBeLinked($images, $filename, $width, $height, $filesize, $folders);
        }
    }

    function check(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }
    function setValue(object $object, string $property, mixed $value): void
    {
        (new ReflectionProperty($object, $property))->setValue($object, $value);
    }
    function value(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }
    function call(object $object, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($object, $method))->invoke($object, ...$arguments);
    }
    function capture(callable $action): string
    {
        ob_start();
        try { $action(); return ob_get_contents(); }
        finally { ob_end_clean(); }
    }
    function resource(int $id = 196614, int $object = 13720, int $linked = 1, int $age = 45): Resource
    {
        $resource = new Resource();
        $resource->setResourceId($id);
        $resource->setObjectId($object);
        $resource->setLinked($linked);
        $resource->setImportTimestamp(new DateTime('-' . $age . ' days'));
        $resource->setInventoryNumber('fixture');
        $resource->setOriginalFilename($object . '-1.jpg');
        $resource->setWidth(2000);
        $resource->setHeight(1500);
        $resource->setFilesize(12345);
        return $resource;
    }
    function archive(int $id = 196614, int $object = 13720, int $linked = 1): array
    {
        return ['resource_id' => $id, 'object_id' => $object, 'linked' => $linked];
    }
    function image(int $id, ?int $rsId = null, int $folder = 10): array
    {
        return ['id' => $id, 'link_dams' => $rsId === null ? '' : 'fixture:rs/' . $rsId,
            'media_folder_id' => $folder, 'filename' => 'fixture.jpg', 'original_filename' => '13720-1.jpg',
            'width' => 2000, 'height' => 1500, 'filesize' => 12345];
    }
    function context(array $active = [], array $archives = [], array $images = [], int $object = 13720, array $rsIds = [196614]): array
    {
        $connection = new FixtureConnection();
        $connection->archives = $archives;
        $em = new EntityManager($connection, $active);
        $qi = new FixtureQi();
        $rs = new ResourceSpace();
        $http = new HttpUtil();
        $command = (new ReflectionClass(ProcessCommand::class))->newInstanceWithoutConstructor();
        $rsData = [];
        foreach ($rsIds as $id) {
            $rsData[$id] = ['ref' => $id, 'inventory_number' => 'fixture', 'original_filename' => 'fixture.jpg'];
        }
        foreach ([
            'entityManager' => $em, 'qi' => $qi, 'resourceSpace' => $rs, 'httpUtil' => $http, 'update' => true, 'fullProcessing' => true,
            'objectsByObjectId' => [$object => (object) ['id' => $object]],
            'objectsByInventoryNumber' => ['fixture' => (object) ['id' => $object]],
            'qiImages' => [$object => $images], 'qiLinkDamsPrefix' => 'fixture:rs/',
            'linkedResources' => [], 'resourcesByFilename' => ['fixture' => ['01' => $rsData]],
            'resourcesByResourceId' => $rsData, 'rsFields' => ['inventorynumber' => 'inventory_number', 'originalfilename' => 'original_filename'],
            'qiImportMapping' => [], 'qiMappingToSelf' => [], 'rsImportMapping' => [], 'rsFullDataFields' => [],
            'qiUrl' => 'fixture:qi/', 'qiReindexUrl' => 'fixture:reindex/', 'fileSizes' => ['scr'], 'qiMediaFolderIds' => [10]
        ] as $property => $val) { setValue($command, $property, $val); }
        call($command, 'initializeImportTracking', $active);
        return compact('command', 'connection', 'em', 'qi', 'rs', 'http');
    }
    function cycle(array $context): string
    {
        return capture(function () use ($context) {
            foreach (['reportMissingLinks', 'linkImportedResources', 'offloadResources'] as $method) {
                call($context['command'], $method);
            }
        });
    }
    function realQi(FixtureConnection $connection, HttpUtil $http, array $resources = [], bool $full = false, string $class = Qi::class): Qi
    {
        return new $class(new EntityManager($connection, $resources),
            ['api' => ['url' => 'https://offline.invalid/api', 'username' => 'fake', 'password' => 'fake'], 'get_fields' => 'fixture'],
            ['qi_field_prefix' => 'credits', 'languages' => []], false, false, true, $full, false, $http, 1000);
    }
    function record(bool $withImage): object
    {
        $record = (object) ['id' => 13720, 'object_number' => 'fixture'];
        if ($withImage) {
            $record->{'media.image.id'} = [5916];
            $record->{'media.image.link_dams'} = ['fixture:rs/196614'];
        }
        return $record;
    }
    function inventoryContext(string $rsNumber, array $records, array $active = [], array $images = []): array
    {
        $objectId = (int) $records[0]->id;
        $context = context($active, [], $images, $objectId);
        $db = new FixtureConnection();
        foreach ($records as $record) { $db->cache[(int) $record->id] = $record; }
        $qi = realQi($db, new HttpUtil());
        $context['matchingLog'] = capture(fn() => $qi->retrieveAllObjects('1 week'));
        setValue($context['command'], 'objectsByObjectId', $qi->getObjectsByObjectId());
        setValue($context['command'], 'objectsByInventoryNumber', $qi->getObjectsByInventoryNumber());
        $resource = ['ref'=>196614, 'inventory_number'=>$rsNumber, 'original_filename'=>'fixture.jpg'];
        setValue($context['command'], 'resourcesByFilename', ['fixture'=>['01'=>[196614=>$resource]]]);
        setValue($context['command'], 'resourcesByResourceId', [196614=>$resource]);
        setValue($context['command'], 'update', false);
        return $context;
    }
    function test(string $name, callable $action): void
    {
        static $count = 0;
        $action();
        $GLOBALS['passedTests'] = ++$count;
        echo 'PASS ' . $name . PHP_EOL;
    }

    function mediaRecord(array $images = [], string $number = 'fixture', string $online = '1'): object
    {
        $record = (object) ['id'=>13720, 'object_number'=>$number, 'online'=>$online];
        if ($images !== []) {
            foreach (array_keys($images[0]) as $field) {
                $record->{'media.image.' . $field} = array_column($images, $field);
            }
        }
        return $record;
    }
    function partialContext(HttpUtil $http, array $images = [], array $active = [], array $rsIds = [196614], array $archives = []): array
    {
        $c = context($active, $archives, $images, 13720, $rsIds);
        $c['connection']->cache[13720] = mediaRecord($images);
        $qi = realQi($c['connection'], $http, $active, false, FixtureQi::class);
        capture(fn() => $qi->retrieveAllObjects('1 week'));
        foreach (['qi'=>$qi, 'httpUtil'=>$http, 'fullProcessing'=>false, 'update'=>false,
                  'objectsByObjectId'=>$qi->getObjectsByObjectId(),
                  'objectsByInventoryNumber'=>$qi->getObjectsByInventoryNumber()] as $property=>$val) {
            setValue($c['command'], $property, $val);
        }
        $c['qi'] = $qi;
        $c['http'] = $http;
        return $c;
    }
    function objectRequestCount(HttpUtil $http): int
    {
        return count(array_filter($http->requests, fn($url)=>str_contains($url,'/get/object/id/')));
    }

    check(ini_get('allow_url_fopen') === '0', 'Run these tests with php -n -d allow_url_fopen=0');

    test('Old successful registrations survive repeated missing/incomplete Qi responses', function () {
        $active = [resource(196614), resource(196615), resource(196616)];
        $c = context($active, [], [], 13720, [196614, 196615, 196616]);
        for ($i = 0; $i < 4; $i++) { cycle($c); }
        check(count(value($c['command'], 'importedResources')) === 3, 'Registrations were lost');
        check($c['rs']->imageRequests === [] && $c['qi']->metadataWrites === [], 'Unexpected import or relinking');
        check($c['em']->persisted === [], 'Successful history was modified');
    });
    test('Keeping any one of the four duplicates preserves metadata sync without reimport', function () {
        foreach ([[5916,5917,5918], [71709,71710,71708]] as $ids) {
            $c = context([resource(196614),resource(196615),resource(196616)], [],
                [image($ids[0],196614),image($ids[1],196615),image($ids[2],196616)], 13720, [196614,196615,196616]);
            cycle($c);
            check($c['rs']->imageRequests === [], 'Cleanup triggered reimport');
            check(count($c['qi']->metadataWrites) === 3 && $c['qi']->rsMetadataUpdates === 3, 'Metadata sync stopped');
            check(!in_array(true, array_column($c['qi']->metadataWrites, 'addLink'), true), 'Existing links were recreated');
        }
    });
    test('Successful archived history blocks both upload and filename-based relinking', function () {
        foreach ([1,2] as $status) {
            $c = context([], [archive(linked:$status)], [image(5916, null, 99)]);
            cycle($c);
            check($c['rs']->imageRequests === [] && $c['qi']->metadataWrites === [], 'Archived pair was imported or relinked');
            capture(fn() => call($c['command'], 'checkAndUploadImage', (object)['id'=>13720], 'fixture', [], 196614));
            check($c['rs']->imageRequests === [], 'Upload guard was bypassed');
        }
    });
    test('Archived history permits updates on an existing correct RS link', function () {
        $c = context([], [archive()], [image(5916,196614)]);
        cycle($c);
        check(count($c['qi']->metadataWrites) === 1 && !$c['qi']->metadataWrites[0]['addLink'], 'Metadata updates were blocked');
        check($c['rs']->imageRequests === [], 'Existing image was reimported');
    });
    test('An existing correct RS link without a registration is remembered for future runs', function () {
        $c = context([], [], [['id'=>5916,'link_dams'=>'fixture:rs/196614']]);
        cycle($c);
        $registrations = value($c['command'], 'importedResources');
        check(isset($registrations['196614:13720']) && $registrations['196614:13720']->getLinked() === 2,
            'Observed existing link was not registered');
        check($registrations['196614:13720']->getWidth() === 0, 'Missing optional metadata was not initialized');
        setValue($c['command'], 'qiImages', [13720=>[]]);
        cycle($c);
        check($c['rs']->imageRequests === [], 'Previously observed link was reimported');
    });
    test('A timed-out pending import stays reserved and requires manual review', function () {
        $c = context([resource(linked:0,age:15)], [], [image(71709)]);
        $output = cycle($c);
        check(str_contains($output, 'manual review'), 'Missing review warning');
        check($c['http']->requests === [] && $c['qi']->matchingAttempts === 0, 'Expired import was retried');
        check(count(value($c['command'], 'importedResources')) === 1 && $c['rs']->imageRequests === [], 'Expired import was requeued');
    });
    test('A pending duplicate from before the fix is not linked when success history exists', function () {
        $c = context([resource(linked:0,age:1)], [archive()], [image(71709)]);
        cycle($c);
        check($c['http']->requests === [] && $c['qi']->matchingAttempts === 0, 'Known duplicate was retried');
        check($c['qi']->metadataWrites === [] && $c['rs']->imageRequests === [], 'Known duplicate was linked or reimported');
    });
    test('Normal recent pending import can still finish and becomes protected', function () {
        $pending = resource(linked:0,age:1);
        $c = context([$pending], [], [image(71709)]);
        cycle($c);
        check(count($c['http']->requests) === 1 && $pending->getLinked() === 1, 'Normal import did not finish');
        check(count($c['qi']->metadataWrites) === 1 && $c['qi']->metadataWrites[0]['addLink'], 'Missing first link');
        check($c['rs']->imageRequests === [], 'Pending import was uploaded twice');
        setValue($c['command'], 'qiImages', [13720=>[]]);
        setValue($c['command'], 'linkedResources', []);
        cycle($c);
        check($c['rs']->imageRequests === [], 'Finished import was requeued after a missing response');
    });
    test('Unmatched recent imports stay pending instead of being uploaded again', function () {
        $c = context([resource(linked:0,age:1)]);
        cycle($c);
        check($c['qi']->matchingAttempts === 1 && count(value($c['command'], 'importedResources')) === 1, 'Pending registration was lost');
        check($c['rs']->imageRequests === [], 'Recent import was uploaded twice');
    });
    test('History is specific to the RS-resource/Qi-object pair', function () {
        $c = context([resource()], [archive()], [], 99999);
        setValue($c['command'], 'update', false);
        $output = cycle($c);
        check($c['rs']->imageRequests === [196614] && str_contains($output, 'Uploading resource'), 'Another object was incorrectly blocked');
    });
    test('The same resource can retain active registrations for two different objects', function () {
        $c = context([resource(), resource(object:99999)], [], [], 99999);
        cycle($c);
        check(count(value($c['command'], 'importedResources')) === 2, 'Pair registrations overwrote one another');
        check($c['rs']->imageRequests === [], 'A tracked pair was imported again');
    });
    test('Unsuccessful archived attempts do not count as successful history', function () {
        $c = context([], [archive(linked:0)]);
        setValue($c['command'], 'update', false);
        cycle($c);
        check($c['rs']->imageRequests === [196614], 'An unsuccessful archive blocked a new import');
    });
    test('A first filename match still links an existing image and records successful history', function () {
        $c = context([], [], [image(5916,null,99)]);
        cycle($c);
        check(count($c['qi']->metadataWrites) === 1 && $c['qi']->metadataWrites[0]['addLink'], 'First manual-image link was blocked');
        check(isset(value($c['command'], 'previouslyLinkedResources')['196614:13720']), 'Successful first link was not protected');
        check($c['rs']->imageRequests === [], 'Matching image was unnecessarily uploaded');
    });
    test('Partial delta refresh persists across later runs outside the two-week window', function () {
        $db = new FixtureConnection(); $http = new HttpUtil();
        $db->cache[13720] = record(false);
        $http->delta = [record(true)];
        realQi($db,$http)->retrieveAllObjects('1 week');
        $http->delta = [];
        $next = realQi($db,$http,[resource(age:40)]);
        $next->retrieveAllObjects('1 week');
        check(count($next->getMediaInfos($next->getObjectsByObjectId()[13720],[],[])) === 1, 'Fresh media disappeared from cache');
        check(count($http->requests) === 2, 'Cache persistence added remote requests');
    });
    test('Individual refresh is cached before the import ages past two weeks', function () {
        $db = new FixtureConnection(); $http = new HttpUtil();
        $db->cache[13720] = record(false); $http->objects[13720] = record(true);
        realQi($db,$http,[resource(age:13)])->retrieveAllObjects('1 week');
        $next = realQi($db,$http,[resource(age:15)]);
        $next->retrieveAllObjects('1 week');
        check(count($next->getMediaInfos($next->getObjectsByObjectId()[13720],[],[])) === 1, 'Two-week cache regression returned');
        check(count(array_filter($http->requests,fn($url)=>str_contains($url,'/get/object/id/'))) === 1, 'Extra individual fetches were added');
    });
    test('Full runs still write to staging without replacing the active cache early', function () {
        $db = new FixtureConnection(); $http = new HttpUtil(); $db->cache[13720] = record(false);
        call(realQi($db,$http,[],true),'storeObjects',(object)['records'=>[record(true)]],false);
        check(isset($db->staging[13720]->{'media.image.id'}), 'Staging did not receive fresh data');
        check(!isset($db->cache[13720]->{'media.image.id'}), 'Active cache changed before full-run completion');
    });
    test('Partial cache writes still recover from an idle database connection', function () {
        $db = new FixtureConnection(); $http = new HttpUtil(); $db->failNextWrite = true; $http->delta = [record(true)];
        capture(fn() => realQi($db,$http)->retrieveAllObjects('1 week'));
        check($db->closes === 1 && isset($db->cache[13720]), 'Cache retry did not recover');
    });
    test('Inventory matching ignores case for every prefix and preserves source spelling', function () {
        foreach ([['s2022/9','S2022/9'],['DMK08/02a','DMK08/02A'],['DMK10/02D','DMK10/02d'],
                  ['tb2024/140','TB2024/140'],['p75/5','P75/5']] as [$rsNumber,$qiNumber]) {
            $c = inventoryContext($rsNumber, [(object)['id'=>13720,'object_number'=>$qiNumber]]);
            $output = cycle($c);
            check($c['rs']->imageRequests === [196614] && str_contains($output,'Uploading resource'), 'Case-only match failed: ' . $rsNumber);
            check(value($c['command'],'resourcesByResourceId')[196614]['inventory_number'] === $rsNumber, 'RS spelling was changed');
            check(value($c['command'],'objectsByObjectId')[13720]->object_number === $qiNumber, 'Qi spelling was changed');
        }
    });
    test('Case-only matching keeps an existing RS link without creating another copy', function () {
        $c = inventoryContext('S1646A', [(object)['id'=>13720,'object_number'=>'S1646a']], [resource()], [image(5916,196614)]);
        cycle($c);
        check($c['rs']->imageRequests === [], 'Case-only match duplicated an existing image');
        check(count($c['qi']->metadataWrites) === 1 && !$c['qi']->metadataWrites[0]['addLink'], 'Existing link was not used');
    });
    test('Resources with case variants share one sorted inventory group', function () {
        $c = context();
        setValue($c['command'],'debug',false);
        setValue($c['command'],'rsFields', ['linkwithcms'=>'link','originalfilename'=>'filename',
            'inventorynumber'=>'inventory','fileextension'=>'extension','filetype'=>'type']);
        $resources = [
            ['ref'=>1,'link'=>'yes','filename'=>'photo_02.jpg','inventory'=>'DMK10/02D','extension'=>'jpg','type'=>'image'],
            ['ref'=>2,'link'=>'yes','filename'=>'photo_01.jpg','inventory'=>'dmk10/02d','extension'=>'jpg','type'=>'image']
        ];
        call($c['command'],'storeResources',$resources,['yes'=>'yes'],['jpg'],[],[],[]);
        $groups = value($c['command'],'resourcesByInventoryNumber');
        $files = value($c['command'],'resourcesByFilename');
        check(count($groups) === 1 && count($groups['dmk10/02d']) === 2, 'Case variants formed separate groups');
        check(array_keys($files['dmk10/02d']) === ['01','02'], 'Cross-case image ordering was lost');
        check(value($c['command'],'resourcesByResourceId')[1]['inventory'] === 'DMK10/02D', 'Source resource was lowercased');
    });
    test('Different Qi objects with case-only conflicting numbers are not matched arbitrarily', function () {
        $c = inventoryContext('S1646A', [(object)['id'=>13720,'object_number'=>'S1646A'],
                                      (object)['id'=>13721,'object_number'=>'s1646a']]);
        cycle($c);
        check($c['rs']->imageRequests === [] && $c['qi']->metadataWrites === [], 'Ambiguous inventory number was matched');
        check(str_contains($c['matchingLog'],'differing only by case'), 'Missing ambiguity warning');
        check(!isset(value($c['command'],'objectsByInventoryNumber')['s1646a']), 'Ambiguous lookup remained enabled');
    });
    test('A case change on the same Qi object is not considered a collision', function () {
        $db = new FixtureConnection(); $http = new HttpUtil();
        $db->cache[13720] = (object)['id'=>13720,'object_number'=>'s2022/9'];
        $http->delta = [(object)['id'=>13720,'object_number'=>'S2022/9']];
        $qi = realQi($db,$http);
        $output = capture(fn()=>$qi->retrieveAllObjects('1 week'));
        check($qi->getObjectsByInventoryNumber()['s2022/9']->id === 13720, 'Same-object case change blocked matching');
        check($db->cache[13720]->object_number === 'S2022/9', 'Updated Qi spelling was not preserved');
        check(!str_contains($output,'differing only by case'), 'Same object produced an ambiguity warning');
    });
    test('Comma-separated inventory numbers do not enable multi-object linking', function () {
        $c = inventoryContext('TB2024/140, TB2024/141', [(object)['id'=>13720,'object_number'=>'TB2024/140'],
                                                       (object)['id'=>13721,'object_number'=>'TB2024/141']]);
        cycle($c);
        check($c['rs']->imageRequests === [] && $c['qi']->metadataWrites === [], 'Comma list was split into object links');
    });
    test('Punctuation in inventory numbers remains significant', function () {
        $c = inventoryContext('DMK08/02a', [(object)['id'=>13720,'object_number'=>'DMK08/02-a']]);
        cycle($c);
        check($c['rs']->imageRequests === [], 'Matching ignored punctuation');
    });
    test('Partial imports refresh a cached object only once for multiple new resources', function () {
        $http = new HttpUtil(); $http->objects[13720] = mediaRecord();
        $c = partialContext($http, rsIds:[196614,196615]);
        cycle($c);
        check(objectRequestCount($http) === 1, 'A cache hit counted as fresh or the object was fetched repeatedly');
        check($c['rs']->imageRequests === [196614,196615], 'Freshly checked new imports were blocked');
        check(count($c['connection']->writes) === 1, 'The fresh object was not cached once');
    });
    test('A fresh RS link replaces stale images and prevents another upload', function () {
        $http = new HttpUtil(); $http->objects[13720] = mediaRecord([image(5916,196614)]);
        $c = partialContext($http);
        setValue($c['command'], 'update', true);
        cycle($c);
        check(objectRequestCount($http) === 1 && $c['rs']->imageRequests === [], 'Fresh existing link was ignored');
        check(isset(value($c['command'],'qiImages')[13720][5916]), 'Matching used the stale image list');
        check(isset(value($c['command'],'previouslyLinkedResources')['196614:13720']), 'Observed link was not protected');
        check(!$c['qi']->metadataWrites[0]['addLink'], 'Existing link was unnecessarily recreated');
    });
    test('A refreshed filename match links the existing image instead of uploading', function () {
        $http = new HttpUtil(); $http->objects[13720] = mediaRecord([image(5916,null,99)]);
        $c = partialContext($http);
        setValue($c['command'], 'update', true);
        cycle($c);
        check($c['rs']->imageRequests === [] && count($c['qi']->metadataWrites) === 1, 'Fresh filename match was not used');
        check($c['qi']->metadataWrites[0] === ['id'=>5916,'addLink'=>true], 'Wrong image was linked');
    });
    test('Delta results count as fresh even when a recent import also refers to that object', function () {
        $http = new HttpUtil(); $http->delta = [mediaRecord()]; $http->objectResponses[13720] = false;
        $c = partialContext($http, active:[resource(196615,age:1)]);
        cycle($c);
        check(objectRequestCount($http) === 0 && count($http->requests) === 1, 'A delta object was fetched again');
        check($c['rs']->imageRequests === [196614], 'Already-fresh object could not receive a new import');
    });
    test('Successful recent-import refreshes are reused for another new resource', function () {
        $http = new HttpUtil(); $http->objects[13720] = mediaRecord();
        $c = partialContext($http, active:[resource(196615,age:1)]);
        cycle($c);
        check(objectRequestCount($http) === 1 && $c['rs']->imageRequests === [196614], 'Recent refresh was repeated or not reused');
    });
    test('Failed or invalid checks postpone first links and uploads without repeated requests', function () {
        foreach ([false,'invalid JSON','{}','{"records":[]}',
                  '{"records":[{"id":99999,"object_number":"fixture"}]}',
                  '{"records":[{"id":13720},{"id":13720}]}'] as $response) {
            $http = new HttpUtil(); $http->objectResponses[13720] = $response;
            $c = partialContext($http, [image(5916,null,99)], rsIds:[196614,196615]);
            setValue($c['command'], 'update', true);
            $output = cycle($c);
            check(objectRequestCount($http) === 1, 'Failed object was requested repeatedly');
            check(str_contains($output,'Deferring new links'), 'Missing postponement warning');
            check($c['qi']->metadataWrites === [] && $c['rs']->imageRequests === [], 'Stale data allowed a link or upload');
            check($c['em']->persisted === [] && $c['connection']->writes === [], 'Failed retrieval changed registrations or cache');
        }
    });
    test('A failed recent-import refresh cannot finish a pending link using stale images', function () {
        $http = new HttpUtil(); $http->objectResponses[13720] = false;
        $pending = resource(linked:0,age:1);
        $c = partialContext($http, [image(5916)], [$pending]);
        setValue($c['command'], 'update', true);
        cycle($c);
        check(objectRequestCount($http) === 1 && $c['qi']->matchingAttempts === 0, 'A failed recent refresh was retried or ignored');
        check($pending->getLinked() === 0 && $c['qi']->metadataWrites === [] && $c['rs']->imageRequests === [], 'Pending link used stale images');
    });
    test('A successfully refreshed pending first import can still finish without another request', function () {
        $http = new HttpUtil(); $http->objects[13720] = mediaRecord([image(71709)]);
        $pending = resource(linked:0,age:1);
        $c = partialContext($http, active:[$pending]);
        setValue($c['command'], 'update', true);
        cycle($c);
        check(objectRequestCount($http) === 1 && $pending->getLinked() === 1, 'Pending first link did not reuse its fresh object');
        check($c['rs']->imageRequests === [] && $c['qi']->metadataWrites[0]['addLink'], 'Pending first import was duplicated');
    });
    test('Full-run new imports and first filename links make no extra Qi requests', function () {
        foreach ([[],[image(5916,null,99)]] as $images) {
            $c = context(images:$images);
            if ($images === []) { setValue($c['command'],'update',false); }
            cycle($c);
            check($c['http']->requests === [], 'Full run made an extra retrieval');
            check($images === [] ? $c['rs']->imageRequests === [196614] : $c['qi']->metadataWrites[0]['addLink'], 'Full-run import behavior changed');
        }
        $http = new HttpUtil(); $qi = realQi(new FixtureConnection(),$http,[],true);
        check($qi->ensureObjectRetrieved(13720) && $http->requests === [], 'Full Qi retrieval guard made a request');
    });
    test('Successful history and observed existing links do not trigger the new partial check', function () {
        foreach ([[[resource()],[],[]],[[],[archive()],[]],[[],[],[image(5916,196614)]]] as [$active,$archives,$images]) {
            $http = new HttpUtil(); $http->objectResponses[13720] = false;
            $c = partialContext($http,$images,$active,archives:$archives);
            cycle($c);
            check(objectRequestCount($http) === 0 && $c['rs']->imageRequests === [], 'Protected or existing link caused a new retrieval/import');
            check(!in_array(true,array_column($c['qi']->metadataWrites,'addLink'),true), 'A successful pair was linked again');
        }
    });
    test('Changed inventory numbers and offline objects do not receive new partial imports', function () {
        foreach ([mediaRecord(number:'different'),mediaRecord(online:'0')] as $fresh) {
            $http = new HttpUtil(); $http->objects[13720] = $fresh;
            $c = partialContext($http);
            (new ReflectionProperty(Qi::class,'onlyOnlineRecords'))->setValue($c['qi'],true);
            cycle($c);
            check(objectRequestCount($http) === 1 && $c['rs']->imageRequests === [], 'Unavailable or changed object received a new import');
            check(!isset(value($c['command'],'objectsByInventoryNumber')['fixture']), 'Stale inventory lookup survived refresh');
        }
    });
    test('Failed freshness checks are retried in the next partial run, not permanently blocked', function () {
        $db = new FixtureConnection(); $http = new HttpUtil(); $db->cache[13720] = mediaRecord();
        $http->objectResponses[13720] = false;
        $qi = realQi($db,$http);
        capture(fn()=>$qi->retrieveAllObjects('1 week'));
        $first = null;
        capture(function () use ($qi,&$first) { $first=$qi->ensureObjectRetrieved(13720); });
        check($first === false && !$qi->ensureObjectRetrieved(13720) && objectRequestCount($http) === 1, 'Failed check was not remembered within the run');
        unset($http->objectResponses[13720]); $http->objects[13720] = mediaRecord();
        capture(fn()=>$qi->retrieveAllObjects('1 week'));
        check($qi->ensureObjectRetrieved(13720) && objectRequestCount($http) === 2, 'Failed check blocked a later run');
    });
    test('Existing RS links take priority over filename matches regardless of image order', function () {
        foreach ([[image(71709,null,99),image(5916,196614)],
                  [image(5916,196614),image(71709,null,99)]] as $images) {
            $http = new HttpUtil(); $http->objects[13720] = mediaRecord($images);
            $c = partialContext($http);
            setValue($c['command'],'update',true);
            cycle($c);
            check(objectRequestCount($http) === 1 && $c['rs']->imageRequests === [], 'Existing resource was uploaded again');
            check($c['qi']->metadataWrites === [['id'=>5916,'addLink'=>false]], 'An unlinked filename match gained a duplicate RS link');
            check(isset(value($c['command'],'previouslyLinkedResources')['196614:13720']), 'Existing correct link was not protected');
        }
    });
    test('A pending first import recognizes an existing RS link before linking another image', function () {
        foreach ([[image(71709),image(5916,196614)],
                  [image(5916,196614),image(71709)]] as $images) {
            $http = new HttpUtil(); $http->objects[13720] = mediaRecord($images);
            $pending = resource(linked:0,age:1);
            $c = partialContext($http,active:[$pending]);
            setValue($c['command'],'update',true);
            cycle($c);
            check(objectRequestCount($http) === 1 && $c['qi']->matchingAttempts === 0, 'Pending import ignored an existing RS link');
            check($pending->getLinked() === 1 && $c['rs']->imageRequests === [], 'Known existing link did not complete the pending registration');
            check($c['qi']->metadataWrites === [['id'=>5916,'addLink'=>false]], 'Pending import linked a second image');
        }
    });
    echo 'All ' . $GLOBALS['passedTests'] . ' offline regression tests passed.' . PHP_EOL;
}
