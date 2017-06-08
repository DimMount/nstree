<?php
/**
 * Copyright (c) 2014 - 2016. ООО "БАРС - 46"
 */

namespace Bars46\NSTree;

use Bitrix\Main;
use Bitrix\Main\Entity;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Class NSDataManager
 * Класс для работы с деревьями nested sets
 *
 * @package Bars46\NSTree
 */
class NSDataManager extends Entity\DataManager
{
    /**
     * @var array - Массив флагов установки обработчиков событий
     */
    protected static $eventHandlers = array();

    /**
     * @var array - Массив полей записи до изменения
     */
    protected static $oldRecord = array();

    /**
     * Отмена автокоммита
     * Блокировка таблицы во избежание разрушения дерева
     */
    public static function lockTable()
    {
        $entity = static::getEntity();
        $connection = $entity->getConnection();
        $tableName = $entity->getDBTableName();
        $tableName2 = strtolower($entity->getCode());
        $sql = "SET AUTOCOMMIT = 0";
        $connection->queryExecute($sql);
        $sql = "LOCK TABLES " . $tableName . " WRITE, " . $tableName . " AS " . $tableName2 . " WRITE";
        $connection->queryExecute($sql);
    }

    /**
     * Разблокирование таблицы
     * Установка автокоммита
     */
    public static function unlockTable()
    {
        $entity = static::getEntity();
        $connection = $entity->getConnection();
        $sql = "UNLOCK TABLES";
        $connection->queryExecute($sql);
        $sql = "SET AUTOCOMMIT = 1";
        $connection->queryExecute($sql);
    }

    /**
     * Добавление новой записи
     *
     * @param array $data - Массив с данными новой записи
     *
     * @return \Bitrix\Main\Entity\AddResult
     */
    public static function add(array $data)
    {
        static::handleEvent(self::EVENT_ON_BEFORE_ADD, 'treeOnBeforeAdd');
        static::handleEvent(self::EVENT_ON_AFTER_ADD, 'treeOnAfterAdd');

        return parent::add($data);
    }

    /**
     * Установка обработчика события
     *
     * @param $eventName - Наименование события
     * @param $eventHandler - Обработчик события
     */
    protected static function handleEvent($eventName, $eventHandler)
    {
        $entity = static::getEntity();
//        $eventType = $entity->getNamespace() . $entity->getName() . '::' . $eventName;
        $eventType = $entity->getName() . $eventName;
        if (!static::$eventHandlers[$eventType]) {
            $eventManager = Main\EventManager::getInstance();
            $eventManager->addEventHandler(
                $entity->getModule(),
                $eventType,
                array(
                    Entity\Base::normalizeEntityClass($entity->getNamespace() . $entity->getName()),
                    $eventHandler
                ),
                false,
                10
            );
            static::$eventHandlers[$eventType] = true;
        }
    }

    /**
     * Обновление записи
     *
     * @param mixed $primary - ИД записи
     * @param array $data - Массив полей записи
     *
     * @return \Bitrix\Main\Entity\UpdateResult
     */
    public static function update($primary, array $data)
    {
        static::handleEvent(self::EVENT_ON_BEFORE_UPDATE, 'treeOnBeforeUpdate');
        static::handleEvent(self::EVENT_ON_AFTER_UPDATE, 'treeOnAfterUpdate');

        return parent::update($primary, $data);
    }

    /**
     * Удаление записи
     *
     * @param mixed $primary - ИД записи
     *
     * @return \Bitrix\Main\Entity\DeleteResult
     */
    public static function delete($primary)
    {
        static::handleEvent(self::EVENT_ON_DELETE, 'treeOnDelete');

        return parent::delete($primary);
    }

    /**
     * Обработчик события перед добавлением новой записи
     *
     * @param \Bitrix\Main\Entity\Event $event
     *
     * @return \Bitrix\Main\Entity\EventResult
     */
    public static function treeOnBeforeAdd(Entity\Event $event)
    {
        $result = new Entity\EventResult;
        $data = $event->getParameter("fields");

        if (!isset($data['SORT'])) {
            $maxSort = static::getList(
                array(
                    'select'  => array('MAX_SORT'),
                    'runtime' => array(
                        new Entity\ExpressionField('MAX_SORT', 'MAX(%s)', array('SORT'))
                    )
                )
            )->fetchAll();
            $result->modifyFields(array('SORT' => intval($maxSort[0]['MAX_SORT']) + 1));
        }

        return $result;
    }

    /**
     * Обработчик события после добавления записи
     *
     * @param \Bitrix\Main\Entity\Event $event
     *
     * @return \Bitrix\Main\Entity\EventResult
     */
    public static function treeOnAfterAdd(Entity\Event $event)
    {
        $result = new Entity\EventResult;
        $data = $event->getParameter("fields");
        $id = $event->getParameter('id');

        $arParent = false;
        if (intval($data["PARENT_ID"]) > 0) {
            $arParent = self::getRow(
                array(
                    'select' => array_filter(array(
                        'ID',
	                    static::hasField('ACTIVE') ? 'ACTIVE' : null,
	                    static::hasField('GLOBAL_ACTIVE') ? 'GLOBAL_ACTIVE' : null,
                        'DEPTH_LEVEL',
                        'LEFT_MARGIN',
                        'RIGHT_MARGIN'
                    )),
                    'filter' => array(
                        '=ID' => $data["PARENT_ID"],
                    )
                )
            );
        }

        //Find rightmost child of the parent
        $arChild = self::getRow(
            array(
                'select' => array_filter(array(
                    'ID',
                    'RIGHT_MARGIN',
	                static::hasField('GLOBAL_ACTIVE') ? 'GLOBAL_ACTIVE' : null,
                    'DEPTH_LEVEL'
                )),
                'filter' => array(
                    '=PARENT_ID' => (intval($data["PARENT_ID"]) > 0 ? $data['PARENT_ID'] : false),
                    '<SORT'      => $data['SORT'],
                    '!ID'        => $id
                ),
                'order'  => array(
                    'SORT' => 'DESC'
                )
            )
        );

        if (!empty($arChild)) {
            //We found the left neighbour
            $arUpdate = array(
                "LEFT_MARGIN"  => intval($arChild["RIGHT_MARGIN"]) + 1,
                "RIGHT_MARGIN" => intval($arChild["RIGHT_MARGIN"]) + 2,
                "DEPTH_LEVEL"  => intval($arChild["DEPTH_LEVEL"]),
            );
            if (static::hasField('GLOBAL_ACTIVE') && static::hasField('ACTIVE')) {
	            //in case we adding active section
	            if ($data["ACTIVE"] != "N") {
	                //Look up GLOBAL_ACTIVE of the parent
	                //if none then take our own
	                if ($arParent)//We must inherit active from the parent
	                {
	                    $arUpdate["GLOBAL_ACTIVE"] = $arParent["ACTIVE"] == "Y" ? "Y" : "N";
	                } else //No parent was found take our own
	                {
	                    $arUpdate["GLOBAL_ACTIVE"] = "Y";
	                }
	            } else {
	                $arUpdate["GLOBAL_ACTIVE"] = "N";
	            }
            }
        } else {
            //If we have parent, when take its left_margin
            if ($arParent) {
                $arUpdate = array_filter(array(
                    "LEFT_MARGIN"   => intval($arParent["LEFT_MARGIN"]) + 1,
                    "RIGHT_MARGIN"  => intval($arParent["LEFT_MARGIN"]) + 2,
	                "GLOBAL_ACTIVE" => static::hasField('GLOBAL_ACTIVE')
		                ? ($arParent["GLOBAL_ACTIVE"] == "Y") && ($data["ACTIVE"] != "N") ? "Y" : "N"
		                : null,
                    "DEPTH_LEVEL"   => intval($arParent["DEPTH_LEVEL"]) + 1,
                ));
            } else {
                //We are only one/leftmost section in the iblock.
                $arUpdate = array_filter(array(
                    "LEFT_MARGIN"   => 1,
                    "RIGHT_MARGIN"  => 2,
	                "GLOBAL_ACTIVE" => static::hasField('GLOBAL_ACTIVE')
		                ? $data["ACTIVE"] != "N" ? "Y" : "N"
		                : null,
                    "DEPTH_LEVEL"   => 1,
                ));
            }
        }

        $entity = static::getEntity();
        $connection = $entity->getConnection();
        $tableName = $entity->getDBTableName();

        $connection->queryExecute("
            UPDATE " . $tableName . " SET
                LEFT_MARGIN = " . $arUpdate["LEFT_MARGIN"] . "
                ,RIGHT_MARGIN = " . $arUpdate["RIGHT_MARGIN"] . "
                ,DEPTH_LEVEL = " . $arUpdate["DEPTH_LEVEL"] .
	            (static::hasField('GLOBAL_ACTIE') ? ",GLOBAL_ACTIVE = '" . $arUpdate["GLOBAL_ACTIVE"] . "'": '') . "
            WHERE
                ID = " . $id . "
        ");

        $connection->queryExecute("
            UPDATE `" . $tableName . "` SET
                LEFT_MARGIN = LEFT_MARGIN + 2
                ,RIGHT_MARGIN = RIGHT_MARGIN + 2
            WHERE
                LEFT_MARGIN >= " . $arUpdate["LEFT_MARGIN"] . "
                AND ID <> " . $id . "
        ");
        if ($arParent) {
            $connection->queryExecute("
                UPDATE `" . $tableName . "` SET
                    RIGHT_MARGIN = RIGHT_MARGIN + 2
                WHERE
                    LEFT_MARGIN <= " . $arParent["LEFT_MARGIN"] . "
                    AND RIGHT_MARGIN >= " . $arParent["RIGHT_MARGIN"] . "
            ");
        }

        return $result;
    }

    /**
     * Обработчик события перед обновлением записи
     *
     * @param \Bitrix\Main\Entity\Event $event
     *
     * @return \Bitrix\Main\Entity\EventResult
     * @throws \Exception
     */
    public static function treeOnBeforeUpdate(Entity\Event $event)
    {
        $result = new Entity\EventResult;
        $data = $event->getParameter("fields");
        $id = $event->getParameter('id');

        $oldRecord = self::getRow(
            array(
                'select' => array_filter(array(
                    'ID',
	                static::hasField('ACTIVE') ? 'ACTIVE' : null,
	                static::hasField('GLOBAL_ACTIVE') ? 'GLOBAL_ACTIVE' : null,
                    'DEPTH_LEVEL',
                    'LEFT_MARGIN',
                    'RIGHT_MARGIN',
                    'SORT',
                    'PARENT_ID'
                )),
                'filter' => array(
                    '=ID' => $id,
                )
            )
        );
        if (empty($oldRecord)) {
            throw new \Exception(Loc::getMessage('NSTREE_ERROR_RECORD_NOT_FOUND'));
        }

        static::setOldRecord(self::EVENT_ON_BEFORE_UPDATE, $oldRecord);

        if (isset($data['PARENT_ID']) && intval($data['PARENT_ID']) != intval($oldRecord['PARENT_ID'])) {
            $recursiveCheck = self::getRow(
                array(
                    'select' => array(
                        'ID'
                    ),
                    'filter' => array(
                        '=ID'           => $data['PARENT_ID'],
                        '>=LEFT_MARGIN' => $oldRecord['LEFT_MARGIN'],
                        '<=LEFT_MARGIN' => $oldRecord['RIGHT_MARGIN']
                    )
                )
            );
            if (!empty($recursiveCheck)) {
                throw new \Exception(Loc::getMessage('NSTREE_ERROR_MOVING_TO_CHILD_NODE'));
            }

            // При смене родителя устанавливаем максимальное значение сортировки,
            // чтобы в новой ветке элемент оказался в конце
            $maxSort = static::getList(
                array(
                    'select'  => array('MAX_SORT'),
                    'runtime' => array(
                        new Entity\ExpressionField('MAX_SORT', 'MAX(%s)', array('SORT'))
                    )
                )
            )->fetchAll();
            $result->modifyFields(array('SORT' => intval($maxSort[0]['MAX_SORT']) + 1));
        }

        return $result;
    }

    /**
     * Обработчик события после обновления записи
     *
     * @param \Bitrix\Main\Entity\Event $event
     *
     * @return \Bitrix\Main\Entity\EventResult
     */
    public static function treeOnAfterUpdate(Entity\Event $event)
    {
        $result = new Entity\EventResult;
        $data = $event->getParameter("fields");
        $id = $event->getParameter('id');
        $oldRecord = static::getOldRecord(self::EVENT_ON_BEFORE_UPDATE);

        $entity = static::getEntity();
        $connection = $entity->getConnection();
        $tableName = $entity->getDBTableName();

        //Move inside the tree
        if (
            (isset($data["SORT"]) && $data["SORT"] != $oldRecord["SORT"])
            ||
            (isset($data["PARENT_ID"]) && $data["PARENT_ID"] != $oldRecord["PARENT_ID"])
        ) {
            //First "delete" from the tree
            $distance = intval($oldRecord["RIGHT_MARGIN"]) - intval($oldRecord["LEFT_MARGIN"]) + 1;
            $connection->queryExecute("
				UPDATE `" . $tableName . "` SET
					LEFT_MARGIN = -LEFT_MARGIN
					,RIGHT_MARGIN = -RIGHT_MARGIN
				WHERE
					LEFT_MARGIN >= " . intval($oldRecord["LEFT_MARGIN"]) . "
					AND LEFT_MARGIN <= " . intval($oldRecord["RIGHT_MARGIN"]) . "
			");
            $connection->queryExecute("
				UPDATE `" . $tableName . "` SET
					RIGHT_MARGIN = RIGHT_MARGIN - " . $distance . "
				WHERE
					RIGHT_MARGIN > " . $oldRecord["RIGHT_MARGIN"] . "
			");
            $connection->queryExecute("
				UPDATE `" . $tableName . "` SET
					LEFT_MARGIN = LEFT_MARGIN - " . $distance . "
				WHERE
					LEFT_MARGIN > " . $oldRecord["LEFT_MARGIN"] . "
			");

            //Next insert into the the tree almost as we do when inserting the new one

            $parentID = isset($data["PARENT_ID"]) ? intval($data["PARENT_ID"]) : intval($oldRecord["PARENT_ID"]);
            $sort = isset($data["SORT"]) ? intval($data["SORT"]) : intval($oldRecord["SORT"]);

            $arParents = array();
            $rsParents = static::getList(
                array(
                    'select' => array_filter(array(
                        'ID',
	                    static::hasField('ACTIVE') ? 'ACTIVE' : null,
	                    static::hasField('GLOBAL_ACTIVE') ? 'GLOBAL_ACTIVE' : null,
                        'DEPTH_LEVEL',
                        'LEFT_MARGIN',
                        'RIGHT_MARGIN'
                    )),
                    'filter' => array(
                        '@ID' => array(
                            intval($oldRecord["PARENT_ID"]),
                            $parentID
                        )
                    )
                )
            );
            while ($arParent = $rsParents->fetch()) {
                $arParents[$arParent["ID"]] = $arParent;
            }
            //Find rightmost child of the parent
            $rsChild = static::getList(
                array(
                    'select' => array(
                        'ID',
                        'RIGHT_MARGIN',
                        'DEPTH_LEVEL'
                    ),
                    'filter' => array(
                        '=PARENT_ID' => ($parentID > 0 ? $parentID : false),
                        '<SORT'      => $sort,
                        '!ID'        => $id
                    ),
                    'order'  => array(
                        'SORT' => 'DESC'
                    )
                )
            );
            if ($arChild = $rsChild->fetch()) {
                //We found the left neighbour
                $arUpdate = array(
                    "LEFT_MARGIN" => intval($arChild["RIGHT_MARGIN"]) + 1,
                    "DEPTH_LEVEL" => intval($arChild["DEPTH_LEVEL"]),
                );
            } else {
                //If we have parent, when take its left_margin
                if (isset($arParents[$parentID]) && $arParents[$parentID]) {
                    $arUpdate = array(
                        "LEFT_MARGIN" => intval($arParents[$parentID]["LEFT_MARGIN"]) + 1,
                        "DEPTH_LEVEL" => intval($arParents[$parentID]["DEPTH_LEVEL"]) + 1,
                    );
                } else {
                    //We are only one/leftmost section in the iblock.
                    $arUpdate = array(
                        "LEFT_MARGIN" => 1,
                        "DEPTH_LEVEL" => 1,
                    );
                }
            }

            $moveDistance = intval($oldRecord["LEFT_MARGIN"]) - $arUpdate["LEFT_MARGIN"];

            $connection->queryExecute("
				UPDATE `" . $tableName . "` SET
					LEFT_MARGIN = LEFT_MARGIN + " . $distance . "
					,RIGHT_MARGIN = RIGHT_MARGIN + " . $distance . "
				WHERE
					LEFT_MARGIN >= " . $arUpdate["LEFT_MARGIN"] . "
			");
            $connection->queryExecute("
				UPDATE `" . $tableName . "` SET
					LEFT_MARGIN = -LEFT_MARGIN - " . $moveDistance . "
					,RIGHT_MARGIN = -RIGHT_MARGIN - " . $moveDistance . "
					" . ($arUpdate["DEPTH_LEVEL"] != intval($oldRecord["DEPTH_LEVEL"]) ? ",DEPTH_LEVEL = DEPTH_LEVEL - " . ($oldRecord["DEPTH_LEVEL"] - $arUpdate["DEPTH_LEVEL"]) : "") . "
				WHERE
					LEFT_MARGIN <= " . (-intval($oldRecord["LEFT_MARGIN"])) . "
					AND LEFT_MARGIN >= " . (-intval($oldRecord["RIGHT_MARGIN"])) . "
			");

            if (isset($arParents[$parentID])) {
                $connection->queryExecute("
					UPDATE `" . $tableName . "` SET
						RIGHT_MARGIN = RIGHT_MARGIN + " . $distance . "
					WHERE
						LEFT_MARGIN <= " . $arParents[$parentID]["LEFT_MARGIN"] . "
						AND RIGHT_MARGIN >= " . $arParents[$parentID]["RIGHT_MARGIN"] . "
				");
            }
        }

        if (static::hasField('GLOBAL_ACTIVE')) {
	        //Check if parent was changed
	        if (isset($data["PARENT_ID"]) && $data["PARENT_ID"] != $oldRecord["PARENT_ID"]) {
	            $arSection = static::getRow(
	                array(
	                    'select' => array(
	                        'ID',
	                        'PARENT_ID',
	                        'ACTIVE',
	                        'GLOBAL_ACTIVE',
	                        'LEFT_MARGIN',
	                        'RIGHT_MARGIN'
	                    ),
	                    'filter' => array(
	                        '=ID' => $id
	                    )
	                )
	            );

	            $arParent = static::getRow(
	                array(
	                    'select' => array(
	                        'ID',
	                        'GLOBAL_ACTIVE',
	                    ),
	                    'filter' => array(
	                        '=ID' => intval($data["PARENT_ID"])
	                    )
	                )
	            );
	            //If new parent is not globally active
	            //or we are not active either
	            //we must be not globally active too
	            if (($arParent && $arParent["GLOBAL_ACTIVE"] == "N") || ($data["ACTIVE"] == "N")) {
	                $connection->queryExecute("
						UPDATE `" . $tableName . "` SET
							GLOBAL_ACTIVE = 'N'
						WHERE
							LEFT_MARGIN >= " . intval($arSection["LEFT_MARGIN"]) . "
							AND RIGHT_MARGIN <= " . intval($arSection["RIGHT_MARGIN"]) . "
					");
	            }
	            //New parent is globally active
	            //And we WAS NOT active
	            //But is going to be
	            elseif ($arSection["ACTIVE"] == "N" && $data["ACTIVE"] == "Y") {
	                static::recalcGlobalActiveFlag($arSection);
	            }
	            //New parent is globally active
	            //And we WAS active but NOT globally active
	            //But is going to be
	            elseif (
	                (!$arParent || $arParent["GLOBAL_ACTIVE"] == "Y")
	                && $arSection["GLOBAL_ACTIVE"] == "N"
	                && ($arSection["ACTIVE"] == "Y" || $data["ACTIVE"] == "Y")
	            ) {
	                static::recalcGlobalActiveFlag($arSection);
	            }
	            //Otherwise we may not to change anything
	        }
	        //Parent not changed
	        //but we are going to change activity flag
	        elseif (isset($data["ACTIVE"]) && $data["ACTIVE"] != $oldRecord["ACTIVE"]) {
	            //Make all children globally inactive
	            if ($data["ACTIVE"] == "N") {
	                $connection->queryExecute("
						UPDATE `" . $tableName . "` SET
							GLOBAL_ACTIVE = 'N'
						WHERE
							LEFT_MARGIN >= " . intval($oldRecord["LEFT_MARGIN"]) . "
							AND RIGHT_MARGIN <= " . intval($oldRecord["RIGHT_MARGIN"]) . "
					");
	            } else {
	                //Check for parent activity
	                $arParent = static::getRow(
	                    array(
	                        'select' => array(
	                            'ID',
	                            'GLOBAL_ACTIVE'
	                        ),
	                        'filter' => array(
	                            '=ID' => intval($oldRecord["PARENT_ID"])
	                        )
	                    )
	                );
	                //Parent is active
	                //and we changed
	                //so need to recalc
	                if (!$arParent || $arParent["GLOBAL_ACTIVE"] == "Y") {
	                    static::recalcGlobalActiveFlag($oldRecord);
	                }
	            }
	        }
        }

        return $result;
    }

    /**
     * Извлечение значений полей записи до изменения в зависимости от типа события
     *
     * @param $eventName
     *
     * @return mixed
     */
    protected static function getOldRecord($eventName)
    {
        $entity = static::getEntity();
        $eventType = $entity->getNamespace() . $entity->getName() . '::' . $eventName;

        return static::$oldRecord[$eventType];
    }

    /**
     * Сохранение значений полей записи до изменения в зависимости от типа события
     *
     * @param $eventName
     * @param $oldRecord
     */
    protected static function setOldRecord($eventName, $oldRecord)
    {
        $entity = static::getEntity();
        $eventType = $entity->getNamespace() . $entity->getName() . '::' . $eventName;
        static::$oldRecord[$eventType] = $oldRecord;
    }

    /**
     * Установка глобального флага активности на всю ветку дерева
     *
     * @param array[] $arSection Массив с данными узла дерева
     *
     * @throws \Bitrix\Main\ArgumentException
     */
    public static function recalcGlobalActiveFlag($arSection)
    {
    	if (!static::hasField('GLOBAL_ACTIVE')) {
    		throw new \LogicException('Missing GLOBAL_ACTIVE field');
	    }

        $entity = static::getEntity();
        $connection = $entity->getConnection();
        $tableName = $entity->getDBTableName();

        //Make all children globally active
        $connection->queryExecute("
			UPDATE `" . $tableName . "` SET
				GLOBAL_ACTIVE = 'Y'
			WHERE
				LEFT_MARGIN >= " . intval($arSection["LEFT_MARGIN"]) . "
				AND RIGHT_MARGIN <= " . intval($arSection["RIGHT_MARGIN"]) . "
		");
        //Select those who is not active
        $arUpdate = array();
        $prev_right = 0;
        $rsChildren = static::getList(
            array(
                'select' => array(
                    'ID',
                    'LEFT_MARGIN',
                    'RIGHT_MARGIN'
                ),
                'filter' => array(
                    '>=LEFT_MARGIN'  => intval($arSection["LEFT_MARGIN"]),
                    '<=RIGHT_MARGIN' => intval($arSection["RIGHT_MARGIN"]),
                    '=ACTIVE'        => 'N'
                ),
                'order'  => array(
                    'LEFT_MARGIN' => 'ASC'
                )
            )
        );
        while ($arChild = $rsChildren->fetch()) {
            if ($arChild["RIGHT_MARGIN"] > $prev_right) {
                $prev_right = $arChild["RIGHT_MARGIN"];
                $arUpdate[] = "(LEFT_MARGIN >= " . $arChild["LEFT_MARGIN"] . " AND RIGHT_MARGIN <= " . $arChild["RIGHT_MARGIN"] . ")\n";
            }
        }
        if (count($arUpdate) > 0) {
            $connection->queryExecute("
				UPDATE `" . $tableName . "` SET
					GLOBAL_ACTIVE = 'N'
				WHERE
					" . implode(" OR ", $arUpdate) . "
			");
        }
    }

    /**
     * Обработчик события перед удалением записи
     *
     * @param \Bitrix\Main\Entity\Event $event
     *
     * @return \Bitrix\Main\Entity\EventResult
     * @throws \Exception
     */
    public static function treeOnDelete(Entity\Event $event)
    {
        $result = new Entity\EventResult;
        $id = $event->getParameter('id');

        $oldRecord = self::getRow(
            array(
                'select' => array(
                    'LEFT_MARGIN',
                    'RIGHT_MARGIN',
                ),
                'filter' => array(
                    '=ID' => $id,
                )
            )
        );
        if (empty($oldRecord)) {
            throw new \Exception(Loc::getMessage('NSTREE_ERROR_RECORD_NOT_FOUND'));
        }

        $childNodes = static::getList(
            array(
                'select'  => array('CNT'),
                'runtime' => array(
                    new Entity\ExpressionField('CNT', 'COUNT(*)')
                ),
                'filter'  => array(
                    '>LEFT_MARGIN'  => $oldRecord['LEFT_MARGIN'],
                    '<RIGHT_MARGIN' => $oldRecord['RIGHT_MARGIN']
                )
            )
        )->fetch();
        if ($childNodes['CNT'] > 0) {
            throw new \Exception(Loc::getMessage('NSTREE_ERROR_NODE_CONTAINS_CHILDS'));
        }

        $entity = static::getEntity();
        $connection = $entity->getConnection();
        $tableName = $entity->getDBTableName();

        $connection->queryExecute("
			UPDATE `" . $tableName . "` 
			SET
				RIGHT_MARGIN = RIGHT_MARGIN - 2
			WHERE
				RIGHT_MARGIN > " . $oldRecord['RIGHT_MARGIN'] . "
		");

        $connection->queryExecute("
			UPDATE `" . $tableName . "` 
			SET
				LEFT_MARGIN = LEFT_MARGIN - 2
			WHERE
			    LEFT_MARGIN > " . $oldRecord['LEFT_MARGIN'] . "
		");

        return $result;
    }

	/**
	 * Проверяет наличие поля с указанным кодом
	 *
	 * @param string $field
	 * @return bool
	 */
    final public static function hasField($field)
    {
	    /* @var mixed[]|Entity\Field[] $fieldMap  Описание полей */
	    static $fieldMap;

	    if (!isset($fieldMap)) {
		    $fieldMap = static::getMap();
	    }

	    return array_reduce(
		    array_keys($fieldMap),
		    function ($result, $key) use ($field, &$fieldMap) {
			    $item = $fieldMap[$key];
			    return $result || ($item instanceof Entity\Field ? $item->getName() == $field : $key == $field);
		    },
		    false
	    );
    }
}