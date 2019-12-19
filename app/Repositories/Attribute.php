<?php
/**
 * Pim
 * Free Extension
 * Copyright (c) TreoLabs GmbH
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Pim\Repositories;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Templates\Repositories\Base;
use Espo\ORM\Entity;
use Espo\Core\Exceptions\Error;
use Treo\Core\Utils\Util;
use Pim\Entities\Attribute as AttributeEntity;

/**
 * Class Attribute
 *
 * @author r.ratsun@treolabs.com
 */
class Attribute extends Base
{
    /**
     * @inheritdoc
     */
    protected function init()
    {
        parent::init();

        $this->addDependency('language');
    }

    /**
     * @inheritDoc
     *
     * @throws BadRequest
     */
    public function beforeSave(Entity $entity, array $options = [])
    {
        // call parent action
        parent::beforeSave($entity, $options);

        // set sort order
        if (is_null($entity->get('sortOrder'))) {
            $entity->set('sortOrder', (int)$this->max('sortOrder') + 1);
        }

        if (!$this->isTypeValueValid($entity)) {
            throw new BadRequest("The number of 'Values' items should be identical for all locales");
        }

        if (!$entity->isNew() && $entity->isAttributeChanged('sortOrder')) {
            $this->updateSortOrder($entity);
        }
    }

    /**
     * @inheritDoc
     */
    public function afterSave(Entity $entity, array $options = [])
    {
        // create or delete locale attributes if it needs
        $this->updateLocalesAttributes($entity);

        parent::afterSave($entity, $options);
    }

    /**
     * @inheritDoc
     */
    public function max($field)
    {
        $data = $this
            ->getEntityManager()
            ->nativeQuery("SELECT MAX(sort_order) AS max FROM attribute WHERE deleted=0")
            ->fetch(\PDO::FETCH_ASSOC);

        return $data['max'];
    }

    /**
     * @param AttributeEntity $attribute
     * @param array           $locales
     *
     * @return void
     */
    public function createLocaleAttribute(AttributeEntity $attribute, array $locales): void
    {
        foreach ($locales as $locale) {
            $localeAttribute = $this->getEntityManager()->getEntity('Attribute');
            $localeAttribute->set($attribute->toArray());
            $localeAttribute->id = Util::generateId();
            $localeAttribute->set('isMultilang', false);
            $localeAttribute->set('locale', $locale);
            $localeAttribute->set('parentId', $attribute->get('id'));
            $localeAttribute->set('name', $attribute->get('name') . ' › ' . $locale);
            $localeAttribute->set('code', $attribute->get('code') . '_' . strtolower($locale) . '_' . Util::generateId());

            $this->getEntityManager()->saveEntity($localeAttribute);
        }
    }

    /**
     * @inheritdoc
     */
    protected function beforeUnrelate(Entity $entity, $relationName, $foreign, array $options = [])
    {
        if ($relationName == 'products') {
            // prepare data
            $attributeId = (string)$entity->get('id');
            $productId = (is_string($foreign)) ? $foreign : (string)$foreign->get('id');

            if ($this->isProductFamilyAttribute($attributeId, $productId)) {
                throw new Error($this->exception("You can not unlink product family attribute"));
            }
        }
    }

    /**
     * @param AttributeEntity $attribute
     *
     * @return bool
     */
    protected function updateLocalesAttributes(AttributeEntity $attribute): bool
    {
        // exit if has locale
        if (!empty($attribute->get('locale'))) {
            return false;
        }

        // exit if no locales
        if (!$this->getConfig()->get('isMultilangActive', false) || empty($locales = $this->getConfig()->get('inputLanguageList', []))) {
            return false;
        }

        if ($attribute->isNew() && $attribute->get('isMultilang')) {
            $this->createLocaleAttribute($attribute, $locales);
        }

        if (!$attribute->isNew() && $attribute->isAttributeChanged('isMultilang')) {
            if ($attribute->get('isMultilang')) {
                $this->createLocaleAttribute($attribute, $locales);
            } elseif (!empty($attributes = $attribute->get('attributes'))) {
                foreach ($attributes as $item) {
                    if (!empty($item->get('locale'))) {
                        $this->getEntityManager()->removeEntity($item);
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param string $attributeId
     * @param string $productId
     *
     * @return bool
     */
    protected function isProductFamilyAttribute(string $attributeId, string $productId): bool
    {
        $value = $this
            ->getEntityManager()
            ->getRepository('ProductAttributeValue')
            ->select(['id'])
            ->where(['attributeId' => $attributeId, 'productId' => $productId, 'productFamilyId !=' => null])
            ->findOne();

        return !empty($value);
    }

    /**
     * @param string $key
     *
     * @return string
     */
    protected function exception(string $key): string
    {
        return $this->getInjection('language')->translate($key, "exceptions", "Attribute");
    }

    /**
     * @param Entity $entity
     */
    protected function updateSortOrder(Entity $entity): void
    {
        $data = $this
            ->select(['id'])
            ->where(
                [
                    'id!='             => $entity->get('id'),
                    'sortOrder>='      => $entity->get('sortOrder'),
                    'attributeGroupId' => $entity->get('attributeGroupId')
                ]
            )
            ->order('sortOrder')
            ->find()
            ->toArray();

        if (!empty($data)) {
            // create max
            $max = $entity->get('sortOrder');

            // prepare sql
            $sql = '';
            foreach ($data as $row) {
                // increase max
                $max = $max + 10;

                // prepare id
                $id = $row['id'];

                // prepare sql
                $sql .= "UPDATE attribute SET sort_order='$max' WHERE id='$id';";
            }

            // execute sql
            $this->getEntityManager()->nativeQuery($sql);
        }
    }

    /**
     * @param Entity $entity
     *
     * @return bool
     */
    protected function isTypeValueValid(Entity $entity): bool
    {
        if (!empty($entity->get('isMultilang')) && $this->getConfig()->get('isMultilangActive', false) && in_array($entity->get('type'), ['enum', 'multiEnum'])) {
            $count = count($entity->get('typeValue'));
            foreach ($this->getConfig()->get('inputLanguageList', []) as $locale) {
                $field = 'typeValue' . ucfirst(Util::toCamelCase(strtolower($locale)));
                if (count($entity->get($field)) != $count) {
                    return false;
                }
            }
        }

        return true;
    }
}
