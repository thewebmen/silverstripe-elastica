<?php

namespace TheWebmen\Elastica\Forms;

use Elastica\Aggregation\GlobalAggregation;
use Elastica\Query\AbstractQuery;
use SilverStripe\Control\Controller;
use SilverStripe\Control\RequestHandler;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\FormField;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\RelationList;
use TheWebmen\Elastica\Extensions\FilterPageControllerExtension;
use TheWebmen\Elastica\Filters\Filter;
use TheWebmen\Elastica\Interfaces\FilterFieldInterface;
use TheWebmen\Elastica\Model\FacetIndexItemsList;
use TheWebmen\Elastica\Services\ElasticaService;

/**
 * @method RequestHandler|FilterPageControllerExtension getController
 */
class FilterForm extends Form
{
    public function __construct(RequestHandler $controller, $name = self::DEFAULT_NAME)
    {
        $fields = new FieldList();
        $actions = new FieldList();

        parent::__construct($controller, $name, $fields, $actions);

        $sorts = $this->getController()->config()->get('sorts');
        if ($sorts) {
            $sortKeys = array_keys($sorts);
            $fields->push(DropdownField::create('sort', '', array_combine($sortKeys, $sortKeys)));
        }

        foreach ($this->getController()->getFilters() as $filter) {
            $field = $filter->generateFilterField();
            if ($field instanceof FilterFieldInterface) {
                $field->setFilter($filter);
                $filter->setFilterField($field);
                $fields->push($field);
            }
        }

        $this->setFields($fields);

        $this->loadDataFrom($this->getController()->getRequest()->getVars());

        /** @var Filter $filter */
        foreach ($this->getController()->getFilters() as $filter) {
            if ($filter->getAggregation($this->getController()->getFilters())) {
                $aggregation = $this->getController()
                    ->getFilterList()
                    ->getResultSet()
                    ->getAggregation($filter->ID);

                $filter->addAggregation($aggregation);
            }
        }

        $actions->push(FormAction::create('', 'Zoeken')->setAttribute('name', ''));
        $this->setActions($actions);

        $this->setFormMethod('GET');

        $this->setAttribute('action', $this->getController()->getRequest()->getUrl());

        $this->disableSecurityToken();
        $this->loadDataFrom($this->getController()->getRequest()->getVars());
    }

    /**
     * Setter for the form fields.
     * silverstripe 4.3.3 compatibility
     *
     * @param FieldList $fields
     * @return $this
     */
    public function setFields($fields)
    {
        $fields->setForm($this);
        $this->fields = $fields;

        return $this;
    }
}
