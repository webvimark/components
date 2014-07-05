<?php
namespace webvimark\components;

use webvimark\image\Image;
use yii\db\ActiveRecord;
use Yii;
use yii\helpers\Inflector;
use yii\helpers\StringHelper;
use yii\web\UploadedFile;

class BaseActiveRecord extends ActiveRecord
{
	/**
	 * "thumbDir"=>["dimensions"]
	 * If "dimensions" is not array, then image will be saved without resizing (in original size)
	 *
	 * @var array
	 */
	public $thumbs = [
		'full'=>null,
		'medium'=>[300, 300],
		'small'=>[50, 50]
	];

	/**
	 * getUploadDir
	 *
	 * + Создаёт директории, если их нет
	 *
	 * @return string
	 */
	public function getUploadDir()
	{
		return Yii::getAlias('@webroot') . '/images/' . $this->tableName();
	}

	/**
	 * saveImage
	 *
	 * @param UploadedFile $file
	 * @param string        $imageName
	 */
	public function saveImage($file, $imageName)
	{
		if ( ! $file )
			return;

		$uploadDir = $this->getUploadDir();

		$this->prepareUploadDir($uploadDir);

		if ( is_array($this->thumbs) AND !empty($this->thumbs) )
		{

			foreach ($this->thumbs as $dir => $size)
			{
				$img = Image::factory($file->tempName);

				// If $size is array of dimensions - resize, else - just save
				if ( is_array($size) )
					$img->resize(implode(',', $size))->save($uploadDir . '/'. $dir . '/' . $imageName);
				else
					$img->save($uploadDir . '/'. $dir . '/' . $imageName);
			}

			@unlink($file->tempName);
		}
		else
		{
			$file->saveAs($uploadDir . '/' . $imageName);

		}
	}

	/**
	 * Delete image from all directories
	 *
	 * @param string $image
	 */
	public function deleteImage($image)
	{
		$uploadDir = $this->getUploadDir();

		if ( is_array($this->thumbs) AND !empty($this->thumbs) )
		{
			foreach (array_keys($this->thumbs) as $thumbDir)
				@unlink($uploadDir.'/'.$thumbDir.'/'.$image);
		}
		else
		{
			@unlink($uploadDir.'/'.$image);
		}
	}

	/**
	 * getImageUrl
	 *
	 * @param string|null $dir
	 * @param string $attr
	 * @return string
	 */
	public function getImageUrl($dir = 'full', $attr = 'image')
	{
		if ( $dir )
			return Yii::$app->homeUrl . "images/{$this->tableName()}/{$dir}/".$this->{$attr};
		else
			return Yii::$app->homeUrl . "images/{$this->tableName()}/".$this->{$attr};
	}

	/**
	 * getImagePath
	 *
	 * @param string|null $dir
	 * @param string $attr
	 * @return string
	 */
	public function getImagePath($dir = 'full', $attr = 'image')
	{
		if ( $dir )
			return $this->getUploadDir() . "/{$dir}/".$this->{$attr};
		else
			return $this->getUploadDir() . "/".$this->{$attr};
	}


	//=========== Rules ===========

	public function purgeXSS($attr)
	{
		$this->$attr = htmlspecialchars($this->$attr, ENT_QUOTES);
		return true;
	}

	//----------- Rules -----------



	//=========== Protected functions ===========

	/**
	 * prepareUploadDir
	 *
	 * @param string $dir
	 */
	protected function prepareUploadDir($dir)
	{
		if (! is_dir($dir))
		{
			mkdir($dir, 0777, true);
			chmod($dir, 0777);
		}

		// Если нужны папки с thumbs
		if ( is_array($this->thumbs) AND !empty($this->thumbs) )
		{
			foreach (array_keys($this->thumbs) as $thumbDir)
			{
				if (! is_dir($dir.'/'.$thumbDir))
				{
					mkdir($dir.'/'.$thumbDir, 0777, true);
					chmod($dir.'/'.$thumbDir, 0777);
				}
			}
		}
	}

	/**
	 * @param UploadedFile $file
	 *
	 * @return string
	 */
	public function generateFileName($file)
	{
		return uniqid() . '_' . Inflector::slug($file->baseName, '_') . '.' . $file->extension;
	}


	/**
	 * Check if some attributes uploaded via fileInput field
	 * and assign them with UploadedFile
	 *
	 * @inheritdoc
	 */
	public function setAttributes($values, $safeOnly = true)
	{
		parent::setAttributes($values, $safeOnly);

		if ( is_array($values) )
		{
			$attributes = array_flip($safeOnly ? $this->safeAttributes() : $this->attributes());

			$class = StringHelper::basename(get_called_class());

			$schema = $this->getTableSchema();

			foreach ($values as $name => $value)
			{
				if ( isset( $attributes[$name] ) )
				{
					if ( isset($_FILES[$class]['name'][$name]) )
					{
						$this->$name = UploadedFile::getInstance($this, $name);
					}
					// Handle NULL Integrity constraint violation
					elseif ( $value === '' AND $schema->getColumn($name)->type == 'integer' AND !$schema->getColumn($name)->allowNull)
					{
						$defaultValue = $schema->getColumn($name)->defaultValue;

						$this->$name = ($defaultValue !== null) ? $defaultValue : 0;
					}
				}
			}
		}
	}


	/**
	 * @inheritdoc
	 */
	public function beforeSave($insert)
	{
		if ( parent::beforeSave($insert) )
		{
			foreach ($this->attributes as $name => $val)
			{
				if ( $val instanceof UploadedFile )
				{
					if ( $val->name AND !$val->hasError )
					{
						$fileName = $this->generateFileName($val);

						if ( !$this->isNewRecord )
						{
							$this->deleteImage($this->oldAttributes[$name]);
						}

						$this->saveImage($val, $fileName);

						$this->$name = $fileName;
					}
					elseif ( !$this->isNewRecord )
					{
						$this->$name = $this->oldAttributes[$name];
					}
				}
			}

			return true;
		}

		return false;
	}

	public function afterDelete()
	{
		$this->deleteImage($this->image);

		parent::afterDelete();
	}
} 