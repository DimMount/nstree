# Модуль bars46.nstree
Модуль содержит класс **NSDataManager**, унаследованный от Bitrix\Main\Entity\DataManager, позволяющий работать с деревьями Nested Set через API ORM Битрикс.

Для работы с деревом необходимо создать класс-наследник от NSDataManager. 

Метод getMap() обязательно должен содержать поля:
<ul>
<li>ID - Идентификатор записи, первичный ключ</li>
<li>PARENT_ID - Идентификатор родительской записи</li>
<li>LEFT_MARGIN - левый ключ</li>
<li>RIGHT_MARGIN - правый ключ</li>
<li>DEPTH_LEVEL - уровень вложенности</li>
<li>SORT - сортировка</li>
</ul>

Дополнительно поддерживаютя поля:

* ACTIVE - флаг активности
* GLOBAL_ACTIVE - флаг активности всего узла, устанавливается автоматически

## Примеры

Пример ORM-класса находится в файле lib/nstest.php

### Добавление новой записи в корень дерева
```php
Bars46\NSTree\NSTestTable::add(
    array(
        'NAME' => 'ROOT ROW'
    )
);
```

### Добавление записи в существующую ветку

```php
Bars46\NSTree\NSTestTable::add(
    array(
        'PARENT_ID' => $parent_node_id,
        'NAME' => 'CHILD ROW'
    )
);
```

### Перемещение записи или целой ветки в новую ветку

```php
Bars46\NSTree\NSTestTable::update(
    $id,
    array(
        'PARENT_ID' => $new_parent_node_id
    )
);
```

### Получение всего упорядоченного дерева, начиная от корня

```php
$res = Bars46\NSTree\NSTestTable::getList(
    array(
        'select' => array(
            'ID',
            'NAME'
        ),
        'order' => array(
            'LEFT_MARGIN' => 'ASC'
        )
    )
);
```

### Получение только корневых элементов

```php
$res = Bars46\NSTree\NSTestTable::getList(
    array(
        'select' => array(
            'ID',
            'NAME'
        ),
        'filter' => array(
            '=DEPTH_LEVEL' => 1
        ),
        'order' => array(
            'LEFT_MARGIN' => 'ASC'
        )
    )
);
```

### Получение всех потомков конкретной ветки дерева

```php
$node = Bars46\NSTree\NSTestTable::getRow(
    array(
        'select' => array(
            'LEFT_MARGIN',
            'RIGHT_MARGIN'
        ),
        'filter' => array(
            '=ID' => $node_id
        )
    )
);
$res = Bars46\NSTree\NSTestTable::getList(
    array(
        'select' => array(
            'ID',
            'NAME'
        ),
        'filter' => array(
            '>LEFT_MARGIN' => $node['LEFT_MARGIN'],
            '<RIGHT_MARGIN' => $node['RIGHT_MARGIN']
        ),
        'order' => array(
            'LEFT_MARGIN' => 'ASC'
        )
    )
);
```

### Получение всех предков конкретной ветки дерева

```php
$node = Bars46\NSTree\NSTestTable::getRow(
    array(
        'select' => array(
            'LEFT_MARGIN',
            'RIGHT_MARGIN'
        ),
        'filter' => array(
            '=ID' => $node_id
        )
    )
);
$res = Bars46\NSTree\NSTestTable::getList(
    array(
        'select' => array(
            'ID',
            'NAME'
        ),
        'filter' => array(
            '<LEFT_MARGIN' => $node['LEFT_MARGIN'],
            '>RIGHT_MARGIN' => $node['RIGHT_MARGIN']
        ),
        'order' => array(
            'LEFT_MARGIN' => 'ASC'
        )
    )
);
```

## Транзакции

Во избежание разрушения структуры дерева при совместном доступе желательно блокировать таблицу на запись и откатывать изменения при возникновении ошибок.
Для этого служат методы **lockTable()** и **unlockTable()**

```php
$connection = Bitrix\Main\Application::getConnection();
Bars46\NSTree\NSTestTable::lockTable();
try {
    Bars46\NSTree\NSTestTable::add(
        array(
            'PARENT_ID' => $parent_node_id,
            'NAME' => 'CHILD ROW'
        )
    );
    $connection->commitTransaction();
} catch (\Exception $e) {
    $connection->rollbackTransaction();
    Bars46\NSTree\NSTestTable::unlockTable();
    echo($e->getMessage() . "\n");
}
```