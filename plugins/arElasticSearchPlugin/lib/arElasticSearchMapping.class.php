<?php

/*
 * This file is part of Qubit Toolkit.
 *
 * Qubit Toolkit is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Qubit Toolkit is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Qubit Toolkit.  If not, see <http://www.gnu.org/licenses/>.
 */

class arElasticSearchMapping
{
  /**
   * Inner objects
   *
   * @return array
   */
  protected $nestedTypes = null;

  /**
   * Dumps schema as array
   *
   * @return array
   */
  public function asArray()
  {
    return $this->mapping;
  }

  /**
   * Load mapping from array
   *
   * @param array $mapping_array
   */
  public function loadArray($mapping_array)
  {
    if (is_array($mapping_array) && !empty($mapping_array))
    {
      if (count($mapping_array) > 1)
      {
        throw new sfException('A mapping.yml must only contain 1 entry.');
      }

      // Get rid of first level (mapping:)
      $this->mapping = $mapping_array['mapping'];

      $this->camelizeFieldNames();

      $this->fixYamlShorthands();

      $this->excludeNestedOnlyTypes();

      $this->cleanYamlShorthands($this->mapping);
    }
  }

  /**
   * Load mapping from YAML file
   *
   * @param string $file
   */
  public function loadYAML($file)
  {
    $mapping_array = sfYaml::load($file);

    if (!is_array($mapping_array))
    {
      return; // No defined schema here, skipping
    }

    $this->loadArray($mapping_array);
  }

  /**
   * Camelize field names by moving array items
   */
  protected function camelizeFieldNames()
  {
    // Iterate over types (actor, information_object, ...)
    foreach ($this->mapping as $typeName => $typeProperties)
    {
      foreach ($typeProperties['properties'] as $propertyName => &$propertyValue)
      {
        $camelized = lcfirst(sfInflector::camelize($propertyName));

        // Abort field change if the name matches with its original version
        if ($camelized == $propertyName)
        {
          continue;
        }

        // Create new item with the new camelized key
        $this->mapping[$typeName]['properties'][$camelized] = $propertyValue;

        // Unset the old one
        unset($this->mapping[$typeName]['properties'][$propertyName]);
      }
    }
  }

  /**
   * Fixes YAML shorthands
   */
  protected function fixYamlShorthands()
  {
    // First, process special attributes
    foreach ($this->mapping as $typeName => &$typeProperties)
    {
      $this->processPropertyAttributes($typeName, $typeProperties);
    }

    // Next iteration to embed nested types
    foreach ($this->mapping as $typeName => &$typeProperties)
    {
      $this->processForeignTypes($typeProperties);
    }
  }

  /**
   * Clean YAML shorthands
   */
  protected function cleanYamlShorthands(array &$typeProperties)
  {
    foreach ($typeProperties as $key => &$value)
    {
      switch ($key)
      {
        case '_attributes':
        case '_foreign_types':
          unset($typeProperties[$key]);

          break;

        default:
          if (is_array($value))
          {
            $this->cleanYamlShorthands($value);
          }

          break;
      }
    }
  }

  /**
   * Given a mapping, it parses its special attributes and update it accordingly
   */
  protected function processPropertyAttributes($typeName, array &$typeProperties)
  {
    // Stop execution if any special attribute was set
    if (!isset($typeProperties['_attributes']))
    {
      return;
    }

    // Look for special attributes like i18n or timestamp and update the
    // mapping accordingly. For example, 'timestamp' adds the created_at
    // and updated_at fields each time is used.
    foreach ($typeProperties['_attributes'] as $attributeName => $attributeValue)
    {
      switch ($attributeName)
      {
        case 'i18n':
          $this->setIfNotSet($typeProperties['properties'], 'sourceCulture', array('type' => 'string', 'index' => 'not_analyzed', 'include_in_all' => false));

          // We are using the same mapping for all the i18n fields
          $i18nFieldMapping = array('type' => 'string', 'index' => 'not_analyzed', 'include_in_all' => false);

          $nestedI18nFields = array();
          foreach ($this->getI18nFields(lcfirst(sfInflector::camelize($typeName))) as $fieldName)
          {
            $nestedI18nFields[$fieldName] = $i18nFieldMapping;
          }

          if (isset($typeProperties['_attributes']['i18nExtra']))
          {
            foreach ($this->getI18nFields(lcfirst(sfInflector::camelize($typeProperties['_attributes']['i18nExtra']))) as $fieldName)
            {
              $nestedI18nFields[$fieldName] = $i18nFieldMapping;
            }
          }

          // i18n documents (one per culture)
          $nestedI18nObjects = array();
          foreach (QubitSetting::getByScope('i18n_languages') as $setting)
          {
            $culture = $setting->getValue(array('sourceCulture' => true));
            $nestedI18nObjects[$culture] = array(
              'type' => 'object',
              'dynamic' => 'strict',
              'include_in_parent' => false,
              'properties' => $nestedI18nFields);
          }

          // Main i18n object
          $this->setIfNotSet($typeProperties['properties'], 'i18n', array(
            'type' => 'object',
            'dynamic' => 'strict',
            'include_in_root' => true,
            'properties' => $nestedI18nObjects));

          break;

        case 'timestamp':
          $this->setIfNotSet($typeProperties['properties'], 'createdAt', array('type' => 'date'));
          $this->setIfNotSet($typeProperties['properties'], 'updatedAt', array('type' => 'date'));

          break;
      }
    }
  }

  /*
   * Given a class name (eg. Repository or QubitRepostiroy), returns
   * an array of i18n fields
   */
  public static function getI18nFields($class)
  {
    // Use table maps to find existing i18n columns
    $className = str_replace('Qubit', '', $class) . 'I18nTableMap';
    $map = new $className;

    $fields = array();
    foreach ($map->getColumns() as $column)
    {
      if (!$column->isPrimaryKey() && !$column->isForeignKey())
      {
        $colName = $column->getPhpName();

        $fields[] = $colName;
      }
    }

    return $fields;
  }

  /**
   * Given a mapping, adds other objects within it
   */
  protected function processForeignTypes(array &$typeProperties)
  {
    // Stop execution if any foreign type was assigned
    if (!isset($typeProperties['_foreign_types']))
    {
      return;
    }

    foreach ($typeProperties['_foreign_types'] as $fieldName => $foreignTypeName)
    {
      $typeProperties['properties'][$fieldName] = $this->mapping[$foreignTypeName];
    }
  }

  /**
   * Exclude nested types if there are not root objects using them
   */
  protected function excludeNestedOnlyTypes()
  {
    // Iterate over types (actor, information_object, ...)
    foreach ($this->mapping as $typeName => $typeProperties)
    {
      // Pass if nested_only is not set
      if (!isset($typeProperties['_attributes']['nested_only']))
      {
        continue;
      }

      unset($this->mapping[$typeName]);
    }
  }

  /**
   * Sets entry if not set
   *
   * @param string $entry
   * @param string $key
   * @param string $value
   */
  protected function setIfNotSet(&$entry, $key, $value)
  {
    if (!isset($entry[$key]))
    {
      $entry[$key] = $value;
    }
  }
}
