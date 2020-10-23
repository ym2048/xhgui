<?php

namespace XHGui\Options;

use Symfony\Component\OptionsResolver\OptionsResolver;
use XHGui\Searcher\SearcherInterface;

class SearchOptions extends OptionsConfigurator
{
    /**
     * Options for SearchInterface::getAll
     *
     *  - sort:       an array of search criteria (TODO meta.SERVER.REQUEST_TIME => -1 ????)
     *  - direction:  an string, either 'desc' or 'asc'
     *  - page:       an integer, the page to display (e.g. 3)
     *  - perPage:    an integer, how many profiles to display per page (e.g. 25)
     *  - conditions: an array of criteria to match
     *  - projection: ???
     */
    protected function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'sort' => null,
            'direction' => SearcherInterface::DEFAULT_DIRECTION,
            'page' => SearcherInterface::DEFAULT_PAGE,
            'perPage' => null,
            'conditions' => [],
            'projection' => false,
        ]);
        $resolver->setRequired(['sort', 'direction', 'page', 'perPage']);
        $resolver->setAllowedTypes('sort', 'string');
        $resolver->setAllowedTypes('page', 'int');
        $resolver->setAllowedTypes('projection', 'bool');
        $resolver->setAllowedTypes('conditions', 'array');
        $resolver->setAllowedValues('direction', ['asc', 'desc']);
        $resolver->setAllowedValues('sort', ['time']);
    }
}
