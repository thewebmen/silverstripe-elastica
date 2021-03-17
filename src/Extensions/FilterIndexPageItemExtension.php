<?php

namespace TheWebmen\Elastica\Extensions;

use SilverStripe\Core\Environment;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Model\SiteTreeExtension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use TheWebmen\Elastica\Services\ElasticaService;
use TheWebmen\Elastica\Traits\FilterIndexItemTrait;
use TheWebmen\Elastica\Interfaces\IndexItemInterface;
use SilverStripe\Core\ClassInfo;
/**
 * @property SiteTree $owner
 */
class FilterIndexPageItemExtension extends SiteTreeExtension implements IndexItemInterface
{
    use FilterIndexItemTrait;

    const INDEX_SUFFIX = 'page';

    /**
     * @var ElasticaService
     */
    private $elasticaService;

    public function __construct()
    {
        parent::__construct();

        $this->elasticaService = Injector::inst()->get('ElasticaService');

    }

    public function onAfterPublish(&$original)
    {
        $this->elasticaService->setIndex(self::getIndexName())->add($this);

        $this->updateElementsIndex($this->owner);
        $this->updateChildrenElements($this->owner);
    }

    public function onAfterUnpublish()
    {
        $this->updateElementsIndex($this->owner);
        $this->updateChildrenElements($this->owner);

        $this->elasticaService->setIndex(self::getIndexName())->delete($this);
    }

    protected function updateChildrenElements(SiteTree $page)
    {
        foreach ($page->Children() as $pageChild) {
            $this->updateElementsIndex($pageChild);
            $this->updateChildrenElements($pageChild);
        }
    }

    protected function updateElementsIndex($page)
    {
        /** @var DataObject $element */
        foreach ($page->findOwned() as $element) {
            $elementClass = get_class($element);
            if (in_array($elementClass, GridElementIndexExtension::getExtendedClasses())) {
                $element->updateElasticaDocument();
            }
        }
    }

    public function updateElasticaFields(&$fields)
    {
        $fields['ParentID'] = ['type' => 'integer'];
        $fields['PageId'] = ['type' => 'keyword'];
        $fields['Title'] = [
            'type' => 'text',
            'fielddata' => true,
            'fields' => [
                'completion' => [
                    'type' => 'completion'
                ]
            ]
        ];
        $fields['Content'] = ['type' => 'text'];
        $fields['Url'] = ['type' => 'text'];
        $fields[ElasticaService::SUGGEST_FIELD_NAME] = [
            'type' => 'completion',
            'analyzer' => 'suggestion'
        ];
    }

    public function updateElasticaDocumentData(&$data)
    {
        $data['PageId'] = $this->owner->getElasticaPageId();
        $data['ParentID'] = $this->owner->ParentID;
        $data['Title'] = $this->owner->Title;
        $data['Content'] = $this->owner->Content;
        $data['Url'] = $this->owner->AbsoluteLink();

        if (!isset($data[ElasticaService::SUGGEST_FIELD_NAME])) {
            $data[ElasticaService::SUGGEST_FIELD_NAME] = $this->fillSugest(['Title','Content'],$data);
        }

    }


    public static function getIndexName()
    {
        $name =  sprintf('content-%s-%s', Environment::getEnv('ELASTICSEARCH_INDEX'), self::INDEX_SUFFIX);

        if (Environment::getEnv('ELASTICSEARCH_INDEX_CONTENT_PREFIX')) {
            $name = sprintf('%s-%s', Environment::getEnv('ELASTICSEARCH_INDEX_CONTENT_PREFIX'), $name);
        }

        return $name;
    }

    public static function  getExtendedClasses()
    {
        $classes = [];
        foreach (ClassInfo::subclassesFor(SiteTree::class) as $candidate) {
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
