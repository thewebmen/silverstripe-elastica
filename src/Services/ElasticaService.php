<?php

namespace TheWebmen\Elastica\Services;

use Elastica\Document;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use TheWebmen\Elastica\Extensions\FilterIndexDataObjectItemExtension;
use TheWebmen\Elastica\Extensions\FilterIndexItemExtension;
use TheWebmen\Elastica\Extensions\FilterIndexPageItemExtension;
use TheWebmen\Elastica\Extensions\GridElementIndexExtension;
use TheWebmen\Elastica\Interfaces\IndexItemInterface;
use TheWebmen\Elastica\Traits\FilterIndexItemTrait;
Use Elastica\Client as ElasticaClient;
use Translatable;

class ElasticaService
{
    use Extensible;
    use Injectable;
    use Configurable;

    private static $number_of_shards = 1;

    private static $number_of_replicas = 1;

    /** @var ElasticaClient  */
    protected $client;

    /**
     * @var \Elastica\Index
     */
    protected $index;

    public function __construct($config = [])
    {
        $this->client = new ElasticaClient($config);

    }

    public function setIndex($indexName)
    {
        $this->index = $this->client->getIndex($indexName);
        return $this;
    }

    public function getIndex()
    {
        return $this->index;
    }

    /**
     * @param FilterIndexItemTrait $record
     */
    public function add($record)
    {
        $this->index->addDocument($record->getElasticaDocument());
    }

    /**
     * @param FilterIndexItemTrait $record
     */
    public function delete($record)
    {
        $this->index->deleteById($record->getElasticaId());
    }


    public function reindex()
    {
        Versioned::set_reading_mode(Versioned::LIVE);

        foreach ($this->getIndexClasses() as $indexer) {

            $indexName = $this->getindexName($indexer);
            $this->setIndex($indexName);
            echo "Create index $indexName \n";

            $this->index->create([
                'settings' => [
                    'number_of_shards' => self::config()->get('number_of_shards'),
                    'number_of_replicas' => self::config()->get('number_of_replicas'),
                    'analysis' => [
                        'filter' => [
                            'dutch_stop' => [
                                'type' => 'stop',
                                'stopwords' => '_dutch_',
                                'ignore_case' => true
                            ],
                            'filename_stop' => [
                                'type' => 'stop',
                                'stopwords' => ['doc', 'jpg', 'jpeg', 'png', 'pdf', 'exe', 'csv']
                            ],
                            'length' => [
                                'type' => 'length',
                                'min' => 3
                            ]
                        ],
                        'char_filter' => [
                            'html' => [
                                'type' => 'html_strip',
                            ],
                            'number_filter' => [
                                'type' => 'pattern_replace',
                                'pattern' => '\\d+',
                                'replacement' => ''
                            ],
                            'file_filter' => [
                                'type' => 'pattern_replace',
                                'pattern' => '\.[a-z]{1,4}$',
                                'replacement' => ''
                            ]
                        ],
                        'analyzer' => [
                            'suggestion' => [
                                'tokenizer' => 'standard',
                                'filter' => ['dutch_stop', 'lowercase', 'filename_stop', 'length'],
                                'char_filter' => ['html', 'number_filter', 'file_filter'],
                            ]
                        ],
                    ]
                ]
            ], true);
            echo "Done\n";


            $documents = $this->indexDocuments($indexer);

            $this->extend('updateReindexDocuments', $documents);

            echo "Add documents\n";
            if (count($documents) > 0) {
                $this->index->addDocuments($documents);
            }
            echo "Done\n";
        }
    }


    protected function indexDocuments($indexer)
    {
        $documents = [];

        $indexedClasses = $this->getExtendedClasses($indexer);

        foreach ($indexedClasses as $class) {

            echo "Indexing {$class}\n";

            /** @var FilterIndexItemTrait $instance */
            $instance = $class::singleton();

            $mapping = $instance->getElasticaMapping();

            echo "Create mapping\n";
            $mapping->send($this->index);
            echo "Done\n";

            echo "Create documents\n";

            /** @var FilterIndexItemTrait $record */
            foreach (Versioned::get_by_stage($class, 'Live') as $record) {
                /** @var \Elastica\Document $document */
                $document = $record->getElasticaDocument();

                // get words for autocomplete
                $data = $document->getData();

                $analyzed =[];
                foreach (['Title', 'Content'] as $field) {
                   // $analyzed = [];
                    $words=[];
                    $text = $record->getField($field);
                    if (empty($text)) {
                        continue;
                    }

                    $words = array_column($this->index->analyze(['analyzer' => 'suggestion', 'text' => $text]), 'token');
                    $analyzed = array_merge($words, $analyzed);
                }

                $analyzed = array_values(array_unique($analyzed));
                $suggest = ['input' => $analyzed];
                $document->set('suggest', $suggest);

                $documents[] = $document;
                echo "Create documents\n";
            }
            echo "Done\n";
        }

        return $documents;
    }


    public function search(\Elastica\Query $query)
    {
       if (!$this->index){
           $this->setDefaultIndex();
       }
       return $this->index->search($query);
    }


    protected function getIndexClasses() {

        return [
            FilterIndexPageItemExtension::class,
            FilterIndexDataObjectItemExtension::class,
            GridElementIndexExtension::class
        ];
    }

    protected function getIndexName($class)
    {
        return call_user_func(sprintf('%s::%s', $class, 'getIndexName'));
    }

    protected function getExtendedClasses($class)
    {
       return  call_user_func(sprintf('%s::%s', $class, 'getExtendedClasses'));
    }

    protected function setDefaultIndex()
    {
        $this->setIndex(FilterIndexPageItemExtension::getIndexName());

        return $this;
    }
}
