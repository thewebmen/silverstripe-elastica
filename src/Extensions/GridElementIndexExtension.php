<?php
namespace TheWebmen\Elastica\Extensions;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataExtension;
use TheWebmen\Elastica\Extensions\FilterIndexDataObjectItemExtension;
use TheWebmen\Elastica\Services\ElasticaService;
use SilverStripe\Core\Injector\Injector;
use TheWebmen\Elastica\Traits\FilterIndexItemTrait;
use SilverStripe\Core\Environment;
use TheWebmen\Elastica\Interfaces\IndexItemInterface;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;

class GridElementIndexExtension extends DataExtension implements IndexItemInterface
{

    use FilterIndexItemTrait;

    const INDEX_SUFFIX = 'grid-element';

    /**
     * @var ElasticaService
     */
    private $elasticaService;


    public function __construct()
    {
        parent::__construct();

        $this->elasticaService = Injector::inst()->get('ElasticaService');

    }


    public function updateElasticaFields(&$fields)
    {
        $fields['PageId'] = ['type' => 'keyword'];
        $fields['ElementTitle'] = ['type' => 'text'];
        $fields['Content'] = ['type' => 'text'];
        $fields['Title'] = ['type' => 'text'];
        $fields['Url'] = [
            'type' => 'text',
            'fielddata' => true
        ];
        $fields[ElasticaService::SUGGEST_FIELD_NAME] = [
            'type' => 'completion',
            'analyzer' => 'suggestion'
        ];
    }

    public function updateElasticaDocumentData(&$data)
    {
        $page = $this->owner->getPage();

        $pageIsVisible = $page && $this->getPageVisibility($page);

        $data['PageId'] = $pageIsVisible ? $page->getElasticaPageId() : 'none';
        $data['ElementTitle'] = $this->owner->getTitle();

        if ($this->owner->hasField('Content') && !isset($data['Content'])) {
            $data['Content'] = $this->owner->Content;
        }

        if ($pageIsVisible) {
            $data['Url'] = $page->AbsoluteLink();
            $data['Title'] = $page->getTitle();
        }
        if ($data['PageId'] !== 'none' && !isset($data[ElasticaService::SUGGEST_FIELD_NAME])) {
            $data[ElasticaService::SUGGEST_FIELD_NAME] = $this->fillSugest(['Title', 'Content'], $data);
        }
    }

    protected function getPageVisibility(SiteTree $page)
    {
        if (!$page->isPublished()) {
            return false;
        }

        if (!$page->getParent()) {
            return true;
        }

        return $this->getPageVisibility($page->getParent());
    }

    public function onAfterPublish()
    {
        $this->updateElasticaDocument();
    }

    public function onAfterUnpublish()
    {
        $this->elasticaService->setIndex(self::getIndexName())->delete($this);
    }

    public function onBeforeDelete()
    {
        $this->onAfterUnpublish();
    }

    public function updateElasticaDocument()
    {
        $this->elasticaService->setIndex(self::getIndexName())->add($this);
    }

    public static function getIndexName()
    {
        $name =  sprintf('content-%s-%s', Environment::getEnv('ELASTICSEARCH_INDEX'), self::INDEX_SUFFIX);

        if (Environment::getEnv('ELASTICSEARCH_INDEX_CONTENT_PREFIX')) {
            $name = sprintf('%s-%s', Environment::getEnv('ELASTICSEARCH_INDEX_CONTENT_PREFIX'), $name);
        }

        return $name;
    }

    public static function getExtendedClasses()
    {
        $classes = [];
        $candidates = ClassInfo::subclassesFor(DataObject::class);
        foreach ($candidates as $candidate) {
            if (singleton($candidate)->hasExtension(self::class)) {
                $classes[] = $candidate;
            }
        }
        return $classes;
    }

    protected function fillSugest($fields, $data)
    {
        $analyzed =[];
        foreach ($fields as $field) {
            // $analyzed = [];
            $words=[];
            $text = isset($data[$field]) ? $data[$field] : "";
            if (empty($text)) {
                continue;
            }

            $words = array_column($this->elasticaService->getIndex()->analyze(['analyzer' => 'suggestion', 'text' => $text]), 'token');
            $analyzed = array_merge($words, $analyzed);
        }

        $analyzed = array_values(array_unique($analyzed));
        $suggest = ['input' => $analyzed];

        return $suggest;
    }
}
