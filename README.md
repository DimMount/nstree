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
<li>ACTIVE - флаг активности</li>
<li>GLOBAL_ACTIVE - флаг активности всего узла</li>
<li>SORT - сортировка</li>
</ul>

## Работа с деревом

### Добавление новой записи в корень дерева
```php
Bitrix\Main\Loader::includeModule("bars46.nstree");
Bars46\NSTree\NSTestTable::add(
    array(
        'NAME' => 'ROOT #1'
    )
);
```
результат:

| ID | PARENT_ID | ACTIVE | GLOBAL_ACTIVE | SORT | NAME | LEFT_MARGIN | RIGHT_MARGIN | DEPTH_LEVEL |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | NULL | Y | Y | 1 | ROOT #1 | 1 | 2 | 1 |
| 2 | NULL | Y | Y | 2 | ROOT #2 | 3 | 4 | 1 |
