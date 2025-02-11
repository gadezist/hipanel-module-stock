<?php

/*
 * Stock Module for Hipanel
 *
 * @link      https://github.com/hiqdev/hipanel-module-stock
 * @package   hipanel-module-stock
 * @license   BSD-3-Clause
 * @copyright Copyright (c) 2015-2016, HiQDev (http://hiqdev.com/)
 */

namespace hipanel\modules\stock\controllers;

use hipanel\actions\Action;
use hipanel\actions\ComboSearchAction;
use hipanel\actions\IndexAction;
use hipanel\actions\PrepareBulkAction;
use hipanel\actions\RedirectAction;
use hipanel\actions\RenderAction;
use hipanel\actions\VariantsAction;
use hipanel\actions\SmartCreateAction;
use hipanel\actions\SmartPerformAction;
use hipanel\actions\SmartUpdateAction;
use hipanel\actions\SmartDeleteAction;
use hipanel\actions\ValidateFormAction;
use hipanel\actions\ViewAction;
use hipanel\base\CrudController;
use hipanel\filters\EasyAccessControl;
use hipanel\helpers\StringHelper;
use hipanel\modules\stock\actions\FastMoveAction;
use hipanel\modules\stock\actions\ResolveRange;
use hipanel\modules\stock\actions\SetRealSerialsAction;
use hipanel\modules\stock\actions\ValidateSellFormAction;
use hipanel\modules\stock\forms\PartSellByPlanForm;
use hipanel\modules\stock\forms\PartSellForm;
use hipanel\modules\stock\helpers\PartSort;
use hipanel\modules\stock\models\MoveSearch;
use hipanel\modules\stock\models\Part;
use hipanel\widgets\SynchronousCountEnabler;
use hipanel\widgets\SummaryWidget;
use hiqdev\hiart\ActiveQuery;
use hiqdev\hiart\Collection;
use Yii;
use yii\base\DynamicModel;
use yii\base\Event;
use yii\grid\GridView;
use yii\helpers\ArrayHelper;
use yii\web\ConflictHttpException;
use yii\web\Response;

class PartController extends CrudController
{
    public function behaviors()
    {
        return array_merge(parent::behaviors(), [
            [
                'class' => EasyAccessControl::class,
                'actions' => [
                    'create' => 'part.create',
                    'update' => 'part.update',
                    'copy' => 'part.create',
                    'reserve' => 'part.update',
                    'unreserve' => 'part.update',
                    'update-order-no' => 'part.update',

                    'repair' => 'move.create',
                    'replace' => 'move.create',
                    'trash' => 'move.create',
                    'rma' => 'move.create',
                    'move' => 'move.create',
                    'move-by-one' => 'move.create',
                    'sell' => 'part.sell',
                    'sell-by-plan' => 'part.sell',
                    'delete' => 'part.delete',
                    'calculate-sell-sum' => 'part.sell',
                    'fast-move' => 'move.create',

                    '*' => 'part.read',
                ],
            ],
        ]);
    }

    public function actions()
    {
        return array_merge(parent::actions(), [
            'fast-move' => [
                'class' => FastMoveAction::class,
            ],
            'bulk-set-serial' => [
                'class' => PrepareBulkAction::class,
                'view' => '_setSerial',
                'scenario' => 'set-serial',
            ],
            'set-real-serials' => [
                'class' => SetRealSerialsAction::class,
            ],
            'set-serial' => [
                'class' => SmartPerformAction::class,
                'scenario' => 'set-serial',
                'success' => Yii::t('hipanel:stock', 'Serial updated'),
                'error' => Yii::t('hipanel:stock', 'Failed set serial'),
                'POST html | POST ajax' => [
                    'save' => true,
                    'success' => [
                        'class' => RedirectAction::class,
                    ],
                ],
            ],
            'bulk-set-price' => [
                'class' => PrepareBulkAction::class,
                'scenario' => 'set-price',
                'view' => '_setPrice',
            ],
            'set-price' => [
                'class' => SmartUpdateAction::class,
                'scenario' => 'update',
                'success' => Yii::t('hipanel:stock', 'Price changed'),
                'POST html' => [
                    'save' => true,
                    'success' => [
                        'class' => RedirectAction::class,
                    ],
                ],
                'on beforeSave' => function (Event $event) {
                    /** @var \hipanel\actions\Action $action */
                    $action = $event->sender;
                    $data = Yii::$app->request->post('Part');
                    $bulkPrice = $data['price'];
                    $bulkCurrency = $data['currency'];
                    unset($data['price'], $data['currency']);
                    $ids = ArrayHelper::getColumn($data, 'id');
                    $parts = Part::find()->where(['ids' => $ids])->limit(-1)->all();
                    $action->collection->set($parts);
                    // TODO: silverfire подумай как переделать
                    foreach ($action->collection->models as $model) {
                        $model->scenario = 'update';
                        $model->price = $bulkPrice;
                        $model->currency = $bulkCurrency;
                    }
                },
            ],
            'change-model' => [
                'class' => SmartUpdateAction::class,
                'scenario' => 'change-model',
                'success' => Yii::t('hipanel:stock', 'Parts have been updated'),
                'view' => 'changeModel',
                'on beforeSave' => function (Event $event) {
                    /** @var \hipanel\actions\Action $action */
                    $action = $event->sender;
                    $parts = Yii::$app->request->post('Part');
                    $model_id = ArrayHelper::remove($parts, 'model_id');
                    foreach ($parts as $id => $part) {
                        $parts[$id]['model_id'] = $model_id;
                    }
                    $action->collection->setModel($this->newModel(['scenario' => 'change-model']));
                    $action->collection->load($parts);
                },
            ],
            'update-order-no' => [
                'class' => SmartUpdateAction::class,
                'scenario' => 'update-order-no',
                'success' => Yii::t('hipanel:stock', 'Parts have been updated'),
                'view' => 'updateOrderNo',
                'on beforeSave' => function (Event $event) {
                    /** @var \hipanel\actions\Action $action */
                    $action = $event->sender;
                    $data = [];
                    $groups = Yii::$app->request->post('Part');
                    foreach ($groups as $first_move_id => $group) {
                        $groupIds = ArrayHelper::remove($group, 'ids');
                        foreach ($groupIds as $id) {
                            $group['id'] = $id;
                            $group['first_move_id'] = $first_move_id;
                            $data[$id] = $group;
                        }
                    }
                    $action->collection->setModel($this->newModel(['scenario' => 'update-order-no']));
                    $action->collection->load($data);
                },
            ],
            'index' => [
                'class' => IndexAction::class,
                'view' => 'index',
                'responseVariants' => [
                    'get-total-count' => fn(VariantsAction $action): int => Part::find()->count(),
                    IndexAction::VARIANT_SUMMARY_RESPONSE => function (VariantsAction $action): string {
                        $dataProvider = $action->parent->getDataProvider();
                        $defaultSummary = (new SynchronousCountEnabler($dataProvider, fn(GridView $grid): string => $grid->renderSummary()))();
                        if ($this->indexPageUiOptionsModel->representation !== 'report') {
                            return $defaultSummary;
                        }
                        $local_sums = [];
                        $total_sums = [];
                        foreach ($dataProvider->getModels() as $model) {
                            $local_sums[$model->currency] += $model->price;
                        }
                        $query = $dataProvider->query;
                        $query->andWhere(['groupby' => 'total_price']);
                        foreach ($query->all() as $model) {
                            $total_sums[$model->currency] += $model->price;
                        }

                        return $defaultSummary . SummaryWidget::widget([
                            'local_sums' => $local_sums,
                            'total_sums' => $total_sums,
                        ]);
                    },
                ],
                'on beforePerform' => function (Event $event) {
                    /** @var ActiveQuery $query */
                    $query = $event->sender->getDataProvider()->query;
                    if ($this->indexPageUiOptionsModel->representation === 'profit-report') {
                        $query->joinWith('profit');
                        $query->andWhere(['with_profit' => true]);
                        $query->addSelect('selling');
                    }
                    if ($this->indexPageUiOptionsModel->representation === 'selling') {
                        $query->addSelect('selling');
                    }
                },
                'data' => function ($action) {
                    $representation = $this->indexPageUiOptionsModel->representation;

                    return [
                        'representation' => $representation,
                        'types' => $action->controller->getTypes(),
                        'brands' => $action->controller->getBrands(),
                        'states' => $action->controller->getStates(),
                    ];
                },
            ],
            'view' => [
                'class' => ViewAction::class,
                'on beforePerform' => function (Event $event) {
                    /** @var \hipanel\actions\SearchAction $action */
                    $action = $event->sender;
                    $dataProvider = $action->getDataProvider();
                    $dataProvider->query->joinWith('model');
                    $dataProvider->query->andWhere(['show_deleted' => true]);
                },
                'data' => function ($action) {
                    $moveSearch = new MoveSearch();
                    $moveDataProvider = $moveSearch->search([
                        $moveSearch->formName() => [
                            'part_ids' => $action->getId(),
                            'with_parts' => 1,
                        ],
                    ]);

                    return [
                        'moveDataProvider' => $moveDataProvider,
                    ];
                },
            ],
            'create' => [
                'class' => SmartCreateAction::class,
                'success' => Yii::t('hipanel:stock', 'Part has been created'),
                'on beforeSave' => function (Event $event) {
                    /** @var Action $action */
                    $action = $event->sender;
                    $parts = Yii::$app->request->post('Part');
                    $newParts = [];
                    foreach ($parts as $part) {
                        if (isset($part['dst_ids'])) {
                            foreach ($part['dst_ids'] as $dst_id) {
                                $newParts[] = array_merge($part, ['dst_id' => $dst_id]);
                            }
                        } else {
                            $newParts[] = $part;
                        }
                    }
                    $action->collection->load($newParts);
                },
                'data' => function ($action, $d) {
                    return [
                        'moveTypes' => $action->controller->getMoveTypes('add'),
                        'suppliers' => $action->controller->getSuppliers(),
                        'currencyTypes' => $action->controller->getCurrencyTypes(),
                    ];
                },
                'POST html' => [
                    'save' => true,
                    'success' => [
                        'class' => RedirectAction::class,
                        'url' => function ($action) {
                            return MoveController::getSearchUrl(['id' => $action->model->id]);
                        },
                    ],
                ],
            ],
            'repair' => [
                'class' => SmartUpdateAction::class,
                'success' => Yii::t('hipanel:stock', 'Parts have been moved'),
                'data' => static function ($action, $data) {
                    array_map(fn ($model) => $model->move_type = 'repair', $data['models']);
                    return [
                        'moveTypes' => $action->controller->getMoveTypes('backrma'),
                        'suppliers' => $action->controller->getSuppliers(),
                        'currencyTypes' => $action->controller->getCurrencyTypes(),
                    ];
                },
            ],
            'copy' => [
                'class' => SmartUpdateAction::class,
                'scenario' => 'copy',
                'success' => Yii::t('hipanel', 'Parts have been copied'),
                'GET html | POST selection' => [
                    'class' => RenderAction::class,
                    'data' => function ($action, $originalData) {
                        return call_user_func($action->parent->data, $action, $originalData);
                    },
                    'params' => function ($action) {
                        $models = $action->parent->fetchModels();
                        foreach ($models as $model) {
                            $model->scenario = 'copy';
                            $model->serial = $model->id = null;
                        }

                        return [
                            'models' => $models,
                            'model' => reset($models),
                        ];
                    },
                ],
                'data' => function ($action) {
                    return [
                        'moveTypes' => $action->controller->getMoveTypes('add'),
                        'suppliers' => $action->controller->getSuppliers(),
                        'currencyTypes' => $action->controller->getCurrencyTypes(),
                    ];
                },
            ],
            'trash' => [
                'class' => SmartUpdateAction::class,
                'scenario' => 'trash',
                'success' => Yii::t('hipanel:stock', 'Parts have been moved'),
                'data' => function ($action) {
                    return [
                        'moveTypes' => $action->controller->getMoveTypes('trash'),
                        'suppliers' => $action->controller->getSuppliers(),
                        'currencyTypes' => $action->controller->getCurrencyTypes(),
                    ];
                },
            ],
            'delete' => [
                'class' => SmartDeleteAction::class,
                'success' => Yii::t('hipanel:stock', 'Part has been deleted'),
                'error' => Yii::t('hipanel:stock', 'An error occurred when trying to delete {object}', ['{object}' => Yii::t('hipanel:stock', 'part')]),
                'queryOptions' => [
                    'batch' => false,
                ],
            ],
            'replace' => [
                'class' => SmartUpdateAction::class,
                'scenario' => 'replace',
                'success' => Yii::t('hipanel:stock', 'Part has been replaced'),
                'data' => static function ($action, $data) {
                    array_map(fn ($model) => $model->move_type = 'replace', $data['models']);
                    return [
                        'moveTypes' => $action->controller->getMoveTypes('backrma'),
                        'suppliers' => $action->controller->getSuppliers(),
                        'currencyTypes' => $action->controller->getCurrencyTypes(),
                    ];
                },
            ],
            'update' => [
                'class' => SmartUpdateAction::class,
                'success' => Yii::t('hipanel:stock', 'Part has been updated'),
                'data' => function ($action) {
                    return [
                        'currencyTypes' => $action->controller->getCurrencyTypes(),
                    ];
                },
            ],
            'reserve' => [
                'class' => SmartUpdateAction::class,
                'success' => Yii::t('hipanel:stock', 'Parts have been updated'),
            ],
            'unreserve' => [
                'class' => SmartUpdateAction::class,
                'view' => 'reserve',
                'success' => Yii::t('hipanel:stock', 'Parts have been updated'),
            ],
            'move-by-one' => [
                'class' => SmartUpdateAction::class,
                'scenario' => 'move-by-one',
                'success' => Yii::t('hipanel:stock', 'Parts have been moved'),
                'view' => 'moveByOne',
                'data' => function ($action) {
                    return [
                        'types' => $action->controller->getMoveTypes('move'),
                        'remotehands' => $action->controller->getRemotehands(),
                    ];
                },
            ],
            'rma' => [
                'class' => SmartUpdateAction::class,
                'success' => Yii::t('hipanel:stock', 'Parts have been moved to RMA'),
                'scenario' => 'move-by-one',
                'view' => 'moveByOne',
                'data' => function ($action) {
                    return [
                        'types' => $action->controller->getMoveTypes('rma'),
                        'remotehands' => $action->controller->getRemotehands(),
                    ];
                },
            ],
            'move' => [
                'class' => SmartUpdateAction::class,
                'success' => Yii::t('hipanel:stock', 'Parts have been moved'),
                'findOptions' => ['limit' => 'all'],
                'GET html | POST selection' => [
                    'class' => RenderAction::class,
                    'data' => function ($action, $originalData) {
                        return call_user_func($action->parent->data, $action, $originalData);
                    },
                    'params' => function ($action) {
                        $groupedModels = [];
                        $models = $action->parent->fetchModels();
                        $groupBy = Yii::$app->request->get('groupBy');
                        $groupModel = new DynamicModel(compact('groupBy'));
                        $groupModel->addRule('groupBy', 'integer');
                        $groupModel->addRule('groupBy', 'in', ['range' => [2, 4, 6, 8, 16]]);
                        foreach ($models as $model) {
                            $model->scenario = 'move';
                            $model->src_id = $model->dst_id;
                            $model->dst_id = null;
                        }
                        $models = ArrayHelper::index($models, 'id', ['src_id']);
                        if ($groupBy !== null && $groupModel->validate()) {
                            foreach ($models as $src_id => $group) {
                                $groupedModels[$src_id] = array_chunk($group, $groupBy, true);
                            }
                        }

                        return [
                            'models' => $models,
                            'groupedModels' => $groupedModels,
                        ];
                    },
                ],
                'data' => function ($action) {
                    return [
                        'types' => $action->controller->getMoveTypes('move'),
                        'remotehands' => $action->controller->getRemotehands(),
                    ];
                },
                'on beforeSave' => function (Event $event) {
                    /** @var \hipanel\actions\Action $action */
                    $action = $event->sender;
                    $data = [];
                    $partGroups = Yii::$app->request->post('Part');
                    foreach ($partGroups as $src_id => $partGroup) {
                        $groupIds = ArrayHelper::remove($partGroup, 'id');
                        foreach ($groupIds as $id) {
                            $partGroup['id'] = $id;
                            $data[$id] = $partGroup;
                        }
                    }
                    $action->collection->setModel($this->newModel(['scenario' => 'move']));
                    $action->collection->load($data);
                },
            ],
            'validate-form' => [
                'class' => ValidateFormAction::class,
            ],
            'validate-sell-form' => [
                'class' => ValidateSellFormAction::class,
                'scenario' => 'default',
                'validatedInputId' => false,
                'allowDynamicScenario' => false,
            ],
            'validate-sell-by-plan-form' => [
                'class' => ValidateFormAction::class,
                'collection' => [
                    'class' => Collection::class,
                    'model' => new PartSellByPlanForm(),
                ],
            ],
            'resolve-destination-range' => [
                'class' => ResolveRange::class,
            ],
            'locations-list' => [
                'class' => ComboSearchAction::class,
                'on beforeSave' => function ($event) {
                    /** @var Action $action */
                    $action = $event->sender;
                    $action->dataProvider->query->andWhere(['groupby' => 'place'])->limit(-1);
                },
            ],
        ]);
    }

    /**
     * @param integer $id
     * @return string
     */
    public function actionRenderObjectParts($id)
    {
        $parts = Part::find()->joinWith('model')->where(['dst_id' => $id])->limit(-1)->all();
        $parts = PartSort::byGeneralRules()->values($parts);
        $data = ArrayHelper::index($parts, 'id', ['model_type_label', 'model_id']);

        return $this->renderAjax('_objectParts', ['data' => $data]);
    }

    public function getTypes()
    {
        return $this->getRefs('type,model', 'hipanel:stock');
    }

    public function getStates()
    {
        return $this->getRefs('state,part', 'hipanel');
    }


    public function getBrands()
    {
        return $this->getRefs('type,brand', 'hipanel:stock');
    }

    public function getMoveTypes($group = null)
    {
        $query = 'type,move';
        if ($group && in_array($group, ['add', 'backrma', 'change', 'move', 'rma', 'trash'])) {
            $query = 'type,move,' . $group;
        }

        return $this->getRefs($query, 'hipanel:stock', ['orderby' => 'no_asc', 'with_recursive' => true]);
    }

    public function getSuppliers()
    {
        return $this->getRefs('destination,supplier', 'hipanel:stock', ['orderby' => 'name_asc']);
    }

    public function getRemotehands()
    {
        return $this->getRefs('destination,remotehands', 'hipanel:stock', ['orderby' => 'name_asc']);
    }

    /**
     * @return string|\yii\web\Response
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionSell()
    {
        $model = new PartSellForm();
        $action = new SmartUpdateAction('sell', $this);
        $request = Yii::$app->request;
        $session = Yii::$app->session;
        if ($model->load($request->post()) && $model->validate()) {
            try {
                Part::batchPerform('sell', $model->getAttributes());
                $session->addFlash('success', Yii::t('hipanel:stock', 'Parts have been successfully sold.'));
            } catch (\Exception $e) {
                $session->addFlash('error', $e->getMessage());
            }

            return $this->redirect($request->referrer);
        }
        $parts = $action->fetchModels();
        $partsByModelType = $this->sortByModelType($parts);
        $currencyOptions = $this->getCurrencyTypes();
        array_walk($currencyOptions, static function (&$value, $key): void {
            $value = StringHelper::getCurrencySymbol($key);
        });

        return $this->renderAjax('modals/sell', compact('model', 'partsByModelType', 'currencyOptions'));
    }

    public function actionSellByPlan()
    {
        $model = new PartSellByPlanForm();
        $action = new SmartUpdateAction('sell-by-plan', $this);
        $request = Yii::$app->request;
        $session = Yii::$app->session;
        if ($model->load($request->post()) && $model->validate()) {
            try {
                Part::batchPerform('sell-by-plan', $model->getAttributes());
                $session->addFlash('success', Yii::t('hipanel:stock', 'Parts have been successfully sold.'));
            } catch (\Exception $e) {
                $session->addFlash('error', $e->getMessage());
            }

            return $this->redirect($request->referrer);
        }
        $parts = $action->fetchModels();
        $partsByModelType = $this->sortByModelType($parts);

        return $this->renderAjax('modals/sell-by-plan', compact('model', 'partsByModelType'));
    }

    /**
     * @param Part[] $parts
     * @return array
     */
    private function sortByModelType(array $parts = []): array
    {
        $partsByModelType = [];
        if (!empty($parts)) {
            foreach (PartSort::byGeneralRules()->values($parts) as $part) {
                $partsByModelType[$part->model_type_label][] = $part;
            }
        }

        return $partsByModelType;
    }

    public function actionCalculateSellTotal()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $model = new PartSellForm();
        $request = Yii::$app->request;
        if ($request->isAjax && $model->load($request->post())) {
            return [
                'total' => Yii::$app->formatter->asCurrency($model->totalSum, $model->currency),
            ];
        }

        throw new ConflictHttpException();
    }
}
