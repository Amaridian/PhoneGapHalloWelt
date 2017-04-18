
<?php

/**
 * Shopware Premium Plugins
 * Copyright (c) shopware AG
 *
 * According to our dual licensing model, this plugin can be used under
 * a proprietary license as set forth in our Terms and Conditions,
 * section 2.1.2.2 (Conditions of Usage).
 *
 * The text of our proprietary license additionally can be found at and
 * in the LICENSE file you have received along with this plugin.
 *
 * This plugin is distributed in the hope that it will be useful,
 * with LIMITED WARRANTY AND LIABILITY as set forth in our
 * Terms and Conditions, sections 9 (Warranty) and 10 (Liability).
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the plugin does not imply a trademark license.
 * Therefore any rights, title and interest in our trademarks
 * remain entirely with us.
 */

use Doctrine\DBAL\Query\QueryBuilder;
use Shopware\Bundle\SearchBundle\Criteria;
use Shopware\Bundle\SearchBundle\Sorting\PriceSorting;
use Shopware\Bundle\StoreFrontBundle\Service;
use Shopware\Bundle\StoreFrontBundle\Struct\ShopContextInterface;
use Shopware\Components\ProductStream\RepositoryInterface;
use Shopware\Models\Shop\Shop;
use ShopwarePlugins\SwagProductAdvisor\Bundle\AdvisorBundle\Struct\Advisor;
use ShopwarePlugins\SwagProductAdvisor\Bundle\AdvisorBundle\Struct\AdvisorAttribute;
use ShopwarePlugins\SwagProductAdvisor\Bundle\SearchBundle\AdvisorSorting;
use ShopwarePlugins\SwagProductAdvisor\Components\Helper\BackendLocale;
use ShopwarePlugins\SwagProductAdvisor\Components\Helper\BackendStreamHelper;
use ShopwarePlugins\SwagProductAdvisor\Components\Helper\RewriteUrlGenerator;
use ShopwarePlugins\SwagProductAdvisor\Components\Helper\TranslationService;
use ShopwarePlugins\SwagProductAdvisor\Components\Helper\UrlGenerator;

/**
 * Class Shopware_Controllers_Backend_Advisor
 */
class Shopware_Controllers_Backend_Advisor extends Shopware_Controllers_Backend_Application
{
    protected $model = 'Shopware\CustomModels\ProductAdvisor\Advisor';
    protected $alias = 'advisor';

    /**
     * Overrides the original getDetail to generate a link to the advisor.
     *
     * @param int $id
     * @return array
     */
    public function getDetail($id)
    {
        $detailArray = parent::getDetail($id);

        $detailArray['data'] = $this->prepareAdvisorData($detailArray['data']);

        return $detailArray;
    }

    /**
     * We need to override this to add all the important additional tables by joining them.
     * Additionally we need the order-by.
     *
     * @inheritdoc
     */
    protected function getDetailQuery($id)
    {
        $builder = parent::getDetailQuery($id);

        $builder
            ->leftJoin('advisor.teaserBanner', 'teaserBanner')
            ->leftJoin('advisor.stream', 'stream')
            ->leftJoin('advisor.questions', 'questions')
            ->leftJoin('questions.answers', 'answers')
            ->addOrderBy('questions.order', 'ASC')
            ->addOrderBy('answers.order', 'ASC')
            ->addSelect(['teaserBanner', 'stream', 'questions', 'answers']);

        return $builder;
    }

    /**
     * This method is to delete all the sessions that are created by this advisor.
     *
     * @inheritdoc
     */
    public function delete($id)
    {
        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $entityManager = $this->get('models');
        /** @var \Shopware\CustomModels\ProductAdvisor\Session[] $advisor */
        $sessionArray = $entityManager->getRepository(
            'Shopware\CustomModels\ProductAdvisor\Session'
        )->findBy(['advisor' => $id]);

        foreach ($sessionArray as $session) {
            $entityManager->remove($session);
        }

        $entityManager->flush();

        return parent::delete($id);
    }

    /**
     * This method save the data from inline editing.
     * Currently only the name- and the active-flag are saved.
     */
    public function saveDataInlineAction()
    {
        $id = $this->request->get('id');
        $name = $this->request->get('name');
        $active = ($this->request->get('active') === 'true');

        if (!$id) {
            $this->view->assign([
                'success' => false,
                'message' => 'No ProductAdvisor ID found.'
            ]);
            return;
        }

        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $entityManager = $this->get('models');
        /** @var \Shopware\CustomModels\ProductAdvisor\Advisor $advisor */
        $advisor = $entityManager->getRepository('Shopware\CustomModels\ProductAdvisor\Advisor')->find($id);

        if (!$advisor) {
            $this->view->assign([
                'success' => false,
                'message' => 'No ProductAdvisor found.'
            ]);
            return;
        }

        try {
            $advisor->setName($name);
            $advisor->setActive($active);

            $entityManager->persist($advisor);
            $entityManager->flush();

            $this->view->assign(['success' => true]);
        } catch (\Exception $ex) {
            $this->view->assign([
                'success' => false,
                'message' => $ex->getMessage()
            ]);
        }
    }

    /**
     * Extend the SaveMethod
     *
     * @inheritdoc
     */
    public function save(array $data)
    {
        // at first check for a the Banner to prevent association errors.
        if (!$data['teaserBannerId']) {
            $data['teaserBanner'] = null;
        }

        $parentData = parent::save($data);

        if (!$parentData['success']) {
            return $parentData;
        }

        /** @var TranslationService $translationService */
        $translationService = $this->container->get('advisor.translation_service');
        $translationService->checkForTranslationClone($data['questions'], $parentData['data']['questions']);

        $data = $parentData['data'];

        return parent::save($data);
    }

    /**
     * create a copy of a Advisor by id
     * id come from request
     */
    public function cloneAdvisorAjaxAction()
    {
        $id = $this->request->get('id');

        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $entityManager = $this->get('models');
        $advisor = $entityManager->getRepository('Shopware\CustomModels\ProductAdvisor\Advisor')->find($id);

        if (empty($advisor)) {
            return;
        }

        $newAdvisor = clone $advisor;

        $prefix = $this->getCopyPrefix();

        $newAdvisor->setName($prefix . $advisor->getName());

        $entityManager->persist($newAdvisor);
        $entityManager->flush();

        /** @var TranslationService $translationService */
        $translationService = $this->get('advisor.translation_service');
        $translationService->cloneTranslations($newAdvisor, $advisor);
    }

    /**
     * get all ProductStreams
     */
    public function getProductStreamsAjaxAction()
    {
        /** @var QueryBuilder $builder */
        $builder = $this->get('dbal_connection')->createQueryBuilder();

        $result = $builder->select(['id', 'name'])
            ->from('s_product_streams', 'stream')
            ->execute()
            ->fetchAll(PDO::FETCH_ASSOC);

        $this->view->assign([
            'data' => $result,
            'total' => count($result)
        ]);
    }

    /**
     * needed parameter in request
     *
     *  streamId,
     *  limit,
     *  start,
     *  shopId,
     *  currencyId,
     *  customerGroupKey,
     *
     */
    public function getArticlesByStreamIdAjaxAction()
    {
        $streamId = (int)$this->request->getParam('streamId');

        /** @var ContextServiceInterface $contextService */
        $contextService = Shopware()->Container()->get('shopware_storefront.context_service');
        $context = $contextService->createProductContext(
            (int)$this->request->getParam('shopId'),
            (int)$this->request->getParam('currencyId'),
            $this->request->getParam('customerGroupKey')
        );

        $limit = (int)$this->request->getParam('limit');
        $offset = (int)$this->request->getParam('start');

        $criteria = new Criteria();
        $criteria->offset($offset);
        $criteria->limit($limit);

        /** @var RepositoryInterface $streamRepository */
        $streamRepository = Shopware()->Container()->get('shopware_product_stream.repository');
        $streamRepository->prepareCriteria($criteria, $streamId);

        $productSearch = Shopware()->Container()->get('shopware_search.product_search');
        $result = $productSearch->search($criteria, $context);

        $this->View()->assign([
            'success' => true,
            'data' => array_values($result->getProducts()),
            'total' => $result->getTotalCount()
        ]);
    }

    /**
     * read all AttributeColumns
     *
     * @throws Exception
     */
    public function getAttributesAjaxAction()
    {
        $table = 's_articles_attributes';

        $connection = $this->container->get('dbal_connection');

        $schemaManager = $connection->getSchemaManager();
        $tableColumns = $schemaManager->listTableColumns($table);

        $blackList = [
            'id',
            'articleid',
            'articledetailsid'
        ];

        $columns = [];
        foreach ($tableColumns as $key => $value) {
            if (!in_array($key, $blackList)) {
                $columns[] = ['id' => $key, 'name' => $key];
            }
        }

        $this->view->assign([
            'data' => $columns,
            'total' => count($columns)
        ]);
    }

    /**
     * real all attributeValues from stream
     *
     * need the streamId and attributeColumnName in request
     *
     * @throws Exception
     */
    public function getAttributeValuesAjaxAction()
    {
        $streamId = (int)$this->request->get('streamId');
        $attributeColumn = $this->request->get('attributeColumn');

        /** @var BackendStreamHelper $backendPreview */
        $backendPreview = $this->get('advisor.backend_stream_helper');
        $attributes = $backendPreview->getAttributeValuesByStreamIdAndAttributeColumnName($streamId, $attributeColumn);

        $searchValue = $this->request->get('query');
        if ($searchValue && strlen($searchValue) > 0) {
            $attributes = $this->searchForValue($attributes, strtolower($searchValue));
        }

        $total = count($attributes);
        $result = $this->prepareValues($attributes, $total);

        $this->view->assign([
            'data' => $result,
            'total' => $total
        ]);
    }

    /**
     * @param array $data
     * @param string $searchValue
     *
     * @return array
     */
    protected function searchForValue(array $data, $searchValue)
    {
        $returnArray = [];

        foreach ($data as $row) {
            $key = strtolower($row['key']);
            $value = strtolower($row['value']);

            if (stripos($key, $searchValue) !== false || strpos($value, $searchValue) !== false) {
                $returnArray[] = $row;
            }
        }

        return $returnArray;
    }

    /**
     * @param array $dataArray
     * @param integer $total
     * @return array
     */
    private function prepareValues(array $dataArray, $total)
    {
        $result = [];

        $limit = (int)$this->request->get('limit');
        $offset = (int)$this->request->get('start');

        if (!empty($offset)) {
            $limit = $offset + $limit;
        }

        if ($total < $limit) {
            $limit = $total;
        }

        for ($i = $offset; $i < $limit; $i++) {
            $result[] = $dataArray[$i];
        }

        return $result;
    }

    /**
     * read all manufacturer from stream
     *
     * need the streamId in request
     *
     * @throws Exception
     */
    public function getManufacturerAjaxAction()
    {
        $streamId = $this->request->get('streamId');

        /** @var BackendStreamHelper $backendPreview */
        $backendPreview = $this->get('advisor.backend_stream_helper');
        $manufacturers = $backendPreview->getManufacturerByStreamIds($streamId);

        $searchValue = $this->request->get('query');
        if ($searchValue && strlen($searchValue) > 0) {
            $manufacturers = $this->searchForValue($manufacturers, $searchValue);
        }

        $total = count($manufacturers);
        $result = $this->prepareValues($manufacturers, $total);

        $this->view->assign([
            'data' => $result,
            'total' => $total
        ]);
    }

    /**
     * call all properties from stream
     *
     * need the streamId in request
     */
    public function getPropertiesAjaxAction()
    {
        console.log('xxxxxxxxx');
        
        $streamId = $this->request->get('streamId');

		console.log('aaaaaaaa');

        /** @var BackendStreamHelper $backendPreview */
        $backendPreview = $this->get('advisor.backend_stream_helper');
		console.log('bbbbbbbbbb');
        $properties = $backendPreview->getPropertiesByStreamId($streamId);

		console.log($streamId);
		console.dir($properties);

        $this->view->assign([
            'data' => $properties,
            'total' => count($properties)
        ]);
    }

    /**
     * read all possible propertyValues from stream
     *
     * need the streamId in request
     * need propertyId in request
     *
     * @throws Exception
     */
    public function getPropertyValuesAjaxAction()
    {
        $streamId = $this->request->get('streamId');
        $propertyId = $this->request->get('propertyId');
        
        /** @var BackendStreamHelper $backendPreview */
        $backendPreview = $this->get('advisor.backend_stream_helper');
        $propertyValues = $backendPreview->getPropertyValuesByStreamAndPropertyId($streamId, $propertyId);

        $searchValue = trim($this->request->get('query'));
        if ($searchValue && strlen($searchValue) > 0) {
            $propertyValues = $this->searchForValue($propertyValues, $searchValue);
        }

        $total = count($propertyValues);
        $result = $this->prepareValues($propertyValues, $total);

        $this->view->assign([
            'data' => $result,
            'total' => $total
        ]);
    }

    /**
     * This helper is for the PriceFilter
     * to display the MaxArticlePrice in stream to the Shop owner.
     *
     * need the streamId in request
     *
     */
    public function getMaxPriceAjaxAction()
    {
        $streamId = $this->request->get('streamId');

        /** @var BackendStreamHelper $backendPreview */
        $backendPreview = $this->get('advisor.backend_stream_helper');
        $maxPrice = $backendPreview->getMaxPriceByStreamIds($streamId);

        $this->view->assign([
            'data' => floatval($maxPrice),
        ]);
    }

    /**
     * this action finds all article by the advisorId and answers
     * and assign the result to the View
     */
    public function findArticleAction()
    {
        $advisorId = $this->request->get('advisorId');

        /** @var ShopwarePlugins\SwagProductAdvisor\Components\Helper\AnswerBuilder $answerBuilder */
        $answerBuilder = $this->get('advisor.answer_builder');

        $answers = $answerBuilder->buildAnswers(json_decode($this->request->getParam('answers'), true));

        /** @var ShopContextInterface $context */
        $contextService = $this->get('shopware_storefront.context_service');

        /** @var ShopContextInterface $context */
        $context = $contextService->createProductContext(
            $this->request->getParam('shop'),
            $this->request->getParam('currency'),
            $this->request->getParam('customer')
        );

        /** @var Shop $shop */
        $shop = $this->get('models')
            ->getRepository('Shopware\Models\Shop\Shop')
            ->find($this->request->getParam('shop'));

        $shop->registerResources(Shopware()->Bootstrap());

        /** @var Advisor $advisor */
        $advisor = $this->get('shopware_advisor.service')->get($advisorId, $context, $answers);

        if (!empty($answers)) {
            $result = $this->getAdvisorResult($advisor, $context);
            $this->View()->assign('result', $this->prepareSearchResultForBackendPreview($result));
        }
    }

    /**
     * @param array $result
     *
     * @return array
     */
    private function prepareSearchResultForBackendPreview(array $result)
    {
        $productArray = [];

        foreach ($result as $product) {
            /** @var AdvisorAttribute $advisorAdds */
            $advisorAdds = $product['attributes']['advisor'];

            $search = $product['attributes']['search'];

            $productArray[] = [
                'id' => $product['id'],
                'name' => $product['articleName'],
                'matches' => count($advisorAdds->getMatches()),
                'boost' => $search->get('advisorRanking')
            ];
        }

        return $productArray;
    }

    /**
     * @param Advisor $advisor
     * @param ShopContextInterface $context
     *
     * @return Shopware\Bundle\StoreFrontBundle\Struct\ListProduct[] | []
     */
    private function getAdvisorResult(Advisor $advisor, ShopContextInterface $context)
    {
        /** @var Criteria $criteria */
        $criteria = $this->container->get('shopware_product_stream.criteria_factory')
            ->createCriteria($this->Request(), $context);

        $stream = $this->container->get('shopware_product_stream.repository');
        $stream->prepareCriteria($criteria, $advisor->getStream());

        $criteria->resetSorting();
        $criteria->resetFacets();

        $sorting = new AdvisorSorting($advisor);
        $criteria->addSorting($sorting);
        $criteria->addSorting(new PriceSorting($advisor->getLastListingSort()));
        $criteria->limit(50);

        /** @var \ShopwarePlugins\SwagProductAdvisor\Bundle\SearchBundle\AdvisorSearch $search */
        $search = $this->container->get('shopware_advisor.search');

        $result = $search->search($criteria, $context);

        return array_map(function ($item) {
            return $this->get('legacy_struct_converter')->convertListProductStruct($item);
        }, $result->getProducts());
    }

    /**
     * Create the prefix for a copy of a advisor
     *
     * @return string
     */
    private function getCopyPrefix()
    {
        /** @var BackendLocale $backendLocale */
        $backendLocale = $this->container->get('advisor.backend_language');
        $language = $backendLocale->getBackendLanguage();

        switch ($language) {
            case 'de_DE':
                $prefix = 'Kopie von ';
                break;
            default:
                $prefix = 'Copy of ';
                break;
        }

        return $prefix;
    }

    /**
     * get the answers who was in the Question.
     * this Action was only called by the PriceFilter
     */
    public function getSavedPricesAction()
    {
        $questionId = $this->request->get('questionId');
        $priceAnswers = [];

        if ($questionId) {
            $priceAnswers = $this->container->get('dbal_connection')->createQueryBuilder()
                ->select('*')
                ->from('s_plugin_product_advisor_answer', 'answer')
                ->where('question_id = :questionId')
                ->setParameter(':questionId', $questionId)
                ->execute()
                ->fetchAll(\PDO::FETCH_ASSOC);
        }

        $this->view->assign([
            'data' => $priceAnswers,
        ]);
    }

    /**
     * Creates the advisor seo-URLs.
     * It also supports batch-processing.
     */
    public function seoAdvisorAction()
    {
        @set_time_limit(0);
        $offset = $this->Request()->getParam('offset');
        $limit = $this->Request()->getParam('limit', 50);
        $shopId = (int) $this->Request()->getParam('shopId', 1);
        /** @var Shopware_Components_SeoIndex $seoIndex */
        $seoIndex = $this->container->get('SeoIndex');
        /** @var sRewriteTable $rewriteTable */
        $rewriteTable = $this->container->get('modules')->RewriteTable();
        /** @var RewriteUrlGenerator $rewriteUrlGenerator */
        $rewriteUrlGenerator = $this->container->get('advisor.rewrite_url_generator');

        // Create shop
        $seoIndex->registerShop($shopId);

        $rewriteTable->baseSetup();
        $rewriteUrlGenerator->createRewriteTableAdvisor($offset, $limit, $shopId);

        $this->View()->assign(array(
            'success' => true
        ));
    }

    /**
     * Prepares the advisor-data for the backend.
     * Generates the advisor-url to open the advisor and triggers the thumbnail-url generator for image-questions.
     *
     * @param array $advisorData
     * @return array
     */
    private function prepareAdvisorData(array $advisorData)
    {
        /** @var UrlGenerator $urlGenerator */
        $urlGenerator = $this->get('advisor.url_generator');
        $advisorData['links'] = $urlGenerator->generateStartUrl($advisorData['id'], $advisorData['name']);
        $advisorData['questions'] = $this->prepareImageThumbnails($advisorData['questions']);

        return $advisorData;
    }

    /**
     * Generates the thumbnail-URLs for the image-question answers.
     *
     * @param array $questions
     * @return array
     */
    private function prepareImageThumbnails(array $questions)
    {
        /** @var Service\MediaServiceInterface $mediaService */
        $mediaService = $this->get('shopware_storefront.media_service');
        /** @var Service\ContextServiceInterface $contextService */
        $contextService = $this->get('shopware_storefront.context_service');

        foreach ($questions as &$question) {
            if (!in_array($question['template'], ['checkbox_image', 'radio_image'])) {
                continue;
            }

            foreach ($question['answers'] as &$answer) {
                if (!$answer['mediaId']) {
                    continue;
                }

                $media = $mediaService->get($answer['mediaId'], $contextService->getShopContext());
                if (!$media) {
                    continue;
                }

                $answer['thumbnail'] = $media->getFile();
            }
        }

        return $questions;
    }
}
