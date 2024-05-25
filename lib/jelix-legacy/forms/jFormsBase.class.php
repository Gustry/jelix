<?php
/**
 * @package     jelix
 * @subpackage  forms
 *
 * @author      Laurent Jouanneau
 * @contributor Dominique Papin
 * @contributor Bastien Jaillot, Steven Jehannet
 * @contributor Christophe Thiriot, Julien Issler, Olivier Demah
 *
 * @copyright   2006-2024 Laurent Jouanneau, 2007 Dominique Papin, 2008 Bastien Jaillot
 * @copyright   2008-2015 Julien Issler, 2009 Olivier Demah, 2010 Steven Jehannet
 *
 * @see         https://www.jelix.org
 * @licence     http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public Licence, see LICENCE file
 */

require_once JELIX_LIB_UTILS_PATH.'jDatatype.class.php';

use Jelix\Forms\FormException;
/**
 * base class of all form classes generated by the jform compiler.
 *
 * @package     jelix
 * @subpackage  forms
 */
abstract class jFormsBase
{
    const SECURITY_LOW = 0;
    const SECURITY_CSRF = 1;

    public $securityLevel = 1;

    /**
     * List of all form controls.
     *
     * @var \Jelix\Forms\Controls\AbstractControl[]
     */
    protected $controls = array();

    /**
     * List of top controls.
     *
     * @var \Jelix\Forms\Controls\AbstractControl[]
     */
    protected $rootControls = array();

    /**
     * List of submit buttons.
     *
     * @var \Jelix\Forms\Controls\SubmitControl[]
     */
    protected $submits = array();

    /**
     * Reset button.
     *
     * @var \Jelix\Forms\Controls\ResetControl
     *
     * @since 1.0
     */
    protected $reset;

    /**
     * List of uploads controls.
     *
     * @var \Jelix\Forms\Controls\Upload2Control[]|\Jelix\Forms\Controls\UploadControl[]
     */
    protected $uploads = array();

    /**
     * List of hidden controls.
     *
     * @var \Jelix\Forms\Controls\HiddenControl[]
     */
    protected $hiddens = array();

    /**
     * List of htmleditorcontrols.
     *
     * @var \Jelix\Forms\Controls\HtmlEditorControl[]
     */
    protected $htmleditors = array();

    /**
     * List of wikieditorcontrols.
     *
     * @var \Jelix\Forms\Controls\WikiEditorControl[]
     *
     * @since 1.2
     */
    protected $wikieditors = array();

    /**
     * the data container.
     *
     * @var \Jelix\Forms\FormDataContainer
     */
    protected $container;

    /**
     * content list of available form builder.
     *
     * @var bool
     */
    protected $builders = array();

    /**
     * the form selector.
     *
     * @var string
     */
    protected $sel;

    /**
     * @param string              $sel       the form selector
     * @param \Jelix\Forms\FormDataContainer $container the data container
     * @param bool                $reset     says if the data should be reset
     */
    public function __construct($sel, $container, $reset = false)
    {
        $this->container = $container;
        if ($reset) {
            $this->container->clear();
        }
        $this->container->updatetime = time();
        $this->sel = $sel;
    }

    /**
     * @return string
     */
    public function getSelector()
    {
        return $this->sel;
    }

    /**
     * set form data from request parameters.
     */
    public function initFromRequest()
    {
        $req = jApp::coord()->request;
        if ($this->securityLevel == jFormsBase::SECURITY_CSRF) {
            if (!$this->isValidToken($req->getParam('__JFORMS_TOKEN__'))) {
                throw new jException('jelix~formserr.invalid.token');
            }
        }

        foreach ($this->rootControls as $name => $ctrl) {
            if (
                $ctrl instanceof \Jelix\Forms\Controls\SecretControl
                || $ctrl instanceof \Jelix\Forms\Controls\SecretConfirmControl
                || $ctrl instanceof jFormsControlSecret
                || $ctrl instanceof jFormsControlSecretConfirm
            ) {
                jApp::config()->error_handling['sensitiveParameters'][] = $ctrl->ref;
            }
            if (!$this->container->isActivated($name) || $this->container->isReadOnly($name)) {
                continue;
            }
            $ctrl->setValueFromRequest($req);
        }
    }

    /**
     * check validity of all data form.
     *
     * @return bool true if all is ok
     */
    public function check()
    {
        $this->container->errors = array();
        foreach ($this->rootControls as $name => $ctrl) {
            if ($this->container->isActivated($name)) {
                $ctrl->check();
            }
        }

        return count($this->container->errors) == 0;
    }

    /**
     * prepare an object with values of all controls.
     *
     * @param object $object     the object to fill
     * @param array  $properties array of 'propertyname'=>array('required'=>true/false,
     *                           'defaultValue'=>$value, 'unifiedType'=>$datatype)
     *                           values of datatype = same as jdb unified types
     */
    public function prepareObjectFromControls($object, $properties = null)
    {
        if ($properties == null) {
            $properties = get_object_vars($object);
            foreach ($properties as $n => $v) {
                if (!is_null($v)) {
                    $r = true;
                    $t = gettype($v);
                } else {
                    $t = 'varchar';
                    $r = false;
                }
                $properties[$n] = array('required' => $r, 'defaultValue' => $v, 'unifiedType' => $t);
            }
        }

        foreach ($this->controls as $name => $ctrl) {
            if (!isset($properties[$name])) {
                continue;
            }

            if (is_array($this->container->data[$name])) {
                if (count($this->container->data[$name]) == 1) {
                    $object->{$name} = $this->container->data[$name][0];
                } else {
                    if (jApp::config()->forms['flagPrepareObjectFromControlsContactArrayValues']) {
                        // ugly fix for a specific project
                        $object->{$name} = implode('_', $this->container->data[$name]);
                    } else {
                        continue;
                    }
                }
            } else {
                $object->{$name} = $this->container->data[$name];
            }

            if ($object->{$name} == '' && !$properties[$name]['required']) {
                // if no value and if the property is not required, we set null to it
                $object->{$name} = null;
            } else {
                if (isset($properties[$name]['unifiedType'])) {
                    $type = $properties[$name]['unifiedType'];
                } else {
                    $type = $properties[$name]['datatype'];
                } // for compatibility

                if ($object->{$name} == '' && $properties[$name]['defaultValue'] !== null
                        && in_array(
                            $type,
                            array('int', 'integer', 'double', 'float', 'numeric', 'decimal')
                        )) {
                    $object->{$name} = $properties[$name]['defaultValue'];
                } elseif ($type == 'boolean' && !is_bool($object->{$name})) {
                    $object->{$name} = (intval($object->{$name}) == 1 || strtolower($object->{$name}) === 'true'
                                      || $object->{$name} === 't' || $object->{$name} === 'on');
                } elseif ($ctrl->datatype instanceof jDatatypeLocaleDateTime
                         && $type == 'datetime') {
                    $dt = new jDateTime();
                    $dt->setFromString($object->{$name}, jDateTime::LANG_DTFORMAT);
                    $object->{$name} = $dt->toString(jDateTime::DB_DTFORMAT);
                } elseif ($ctrl->datatype instanceof jDatatypeLocaleDate
                        && $type == 'date') {
                    $dt = new jDateTime();
                    $dt->setFromString($object->{$name}, jDateTime::LANG_DFORMAT);
                    $object->{$name} = $dt->toString(jDateTime::DB_DFORMAT);
                }
            }
        }
    }

    /**
     * set form data from a DAO.
     *
     * @param jDaoRecordBase|string $daoSelector the selector of a dao file or a DAO record
     * @param string                $key         the primary key for the dao. if null, takes
     *                                           the form ID as primary key. Only needed when string
     *                                           dao selector given.
     * @param string                $dbProfile   the jDb profile to use with the dao
     *
     * @throws FormException
     *
     * @return jDaoRecordBase
     *
     * @see jDao
     */
    public function initFromDao($daoSelector, $key = null, $dbProfile = '')
    {
        if (is_object($daoSelector)) {
            $daorec = $daoSelector;
            $daoSelector = $daorec->getSelector();
            $dao = jDao::get($daoSelector, $dbProfile);
        } else {
            $dao = jDao::create($daoSelector, $dbProfile);
            if ($key === null) {
                $key = $this->container->formId;
            }
            $daorec = $dao->get($key);
        }

        if (!$daorec) {
            if (is_array($key)) {
                $key = var_export($key, true);
            }

            throw new FormException(
                'jelix~formserr.bad.formid.for.dao',
                array($daoSelector, $key, $this->sel)
            );
        }

        $prop = $dao->getProperties();
        foreach ($this->controls as $name => $ctrl) {
            if (isset($prop[$name])) {
                $ctrl->setDataFromDao($daorec->{$name}, $prop[$name]['datatype']);
            }
        }

        return $daorec;
    }

    /**
     * prepare a dao with values of all controls.
     *
     * @param string $daoSelector the selector of a dao file
     * @param string $key         the primary key for the dao. if null, takes the form ID as primary key
     * @param string $dbProfile   the jDb profile to use with the dao
     *
     * @return array return three vars : $daorec, $dao, $toInsert which have to be extracted
     *
     * @see jDao
     */
    public function prepareDaoFromControls($daoSelector, $key = null, $dbProfile = '')
    {
        $dao = jDao::get($daoSelector, $dbProfile);

        if ($key === null) {
            $key = $this->container->formId;
        }

        if ($key != null && ($daorec = $dao->get($key))) {
            $toInsert = false;
        } else {
            $daorec = $dao->createRecord();
            if ($key != null) {
                $daorec->setPk($key);
            }
            $toInsert = true;
        }
        $this->prepareObjectFromControls($daorec, $dao->getProperties());

        return compact('daorec', 'dao', 'toInsert');
    }

    /**
     * save data using a dao.
     * it call insert or update depending the value of the formId stored in the container.
     *
     * @param string $daoSelector the selector of a dao file
     * @param string $key         the primary key for the dao. if null, takes the form ID as primary key
     * @param string $dbProfile   the jDb profile to use with the dao
     *
     * @return mixed the primary key of the new record in a case of inserting
     *
     * @see jDao
     */
    public function saveToDao($daoSelector, $key = null, $dbProfile = '')
    {
        $results = $this->prepareDaoFromControls($daoSelector, $key, $dbProfile);
        /*
         * @var  boolean $toInsert
         * @var jDaoRecordBase $daorec
         * @var jDaoFactoryBase $dao
         */
        extract($results); //use a temp variable to avoid notices
        if ($toInsert) {
            // todo : what about updating the formId with the Pk ?
            $dao->insert($daorec);
        } else {
            $dao->update($daorec);
        }

        return $daorec->getPk();
    }

    /**
     * set data from a DAO, in a control.
     *
     * The control must be a container like checkboxes or listbox with multiple attribute.
     * The form should contain a formId
     *
     * The Dao should map to an "association table" : its primary key should be composed by
     * the primary key stored in the formId (or the given primarykey) + the field which will contain one of
     * the values of the control. If this order is not the same as defined into the dao,
     * you should provide the list of property names which corresponds to the primary key
     * in this order : properties for the formId, followed by the property which contains
     * the value.
     *
     * @param string $name            the name of the control
     * @param string $daoSelector     the selector of a dao file
     * @param mixed  $primaryKey      the primary key if the form have no id. (optional)
     * @param mixed  $primaryKeyNames list of field corresponding to primary keys (optional)
     * @param string $dbProfile       the jDb profile to use with the dao
     *
     * @throws FormException
     *
     * @see jDao
     */
    public function initControlFromDao($name, $daoSelector, $primaryKey = null, $primaryKeyNames = null, $dbProfile = '')
    {
        if (!isset($this->controls[$name])) {
            throw new FormException('jelix~formserr.unknown.control2', array($name, $this->sel));
        }

        if (!$this->controls[$name]->isContainer()) {
            throw new FormException('jelix~formserr.control.not.container', array($name, $this->sel));
        }

        if (!$this->container->formId) {
            throw new FormException('jelix~formserr.formid.undefined.for.dao', array($name, $this->sel));
        }

        if ($primaryKey === null) {
            $primaryKey = $this->container->formId;
        }

        if (!is_array($primaryKey)) {
            $primaryKey = array($primaryKey);
        }

        $dao = jDao::create($daoSelector, $dbProfile);

        $conditions = jDao::createConditions();
        if ($primaryKeyNames) {
            $pkNamelist = $primaryKeyNames;
        } else {
            $pkNamelist = $dao->getPrimaryKeyNames();
        }

        foreach ($primaryKey as $k => $pk) {
            $conditions->addCondition($pkNamelist[$k], '=', $pk);
        }

        $results = $dao->findBy($conditions);
        $valuefield = $pkNamelist[$k + 1];
        $val = array();
        foreach ($results as $res) {
            $val[] = $res->{$valuefield};
        }
        $this->controls[$name]->setData($val);
    }

    /**
     * save data of a control using a dao.
     *
     * The control must be a container like checkboxes or listbox with multiple attribute.
     * If the form contain a new record (no formId), you should call saveToDao before
     * in order to get a new id (the primary key of the new record), or you should get a new id
     * by an other way. then you must pass this primary key in the third argument.
     * If the form has already a formId, then it will be used as a primary key, unless
     * you give one in the third argument.
     *
     * The Dao should map to an "association table" : its primary key should be
     * the primary key stored in the formId + the field which will contain one of
     * the values of the control. If this order is not the same as defined into the dao,
     * you should provide the list of property names which corresponds to the primary key
     * in this order : properties for the formId, followed by the property which contains
     * the value.
     * All existing records which have the formid in their keys are deleted
     * before to insert new values.
     *
     * @param string $controlName     the name of the control
     * @param string $daoSelector     the selector of a dao file
     * @param mixed  $primaryKey      the primary key if the form have no id. (optional)
     * @param mixed  $primaryKeyNames list of field corresponding to primary keys (optional)
     * @param string $dbProfile       the jDb profile to use with the dao
     *
     * @throws FormException
     *
     * @see jDao
     */
    public function saveControlToDao($controlName, $daoSelector, $primaryKey = null, $primaryKeyNames = null, $dbProfile = '')
    {
        if (!isset($this->controls[$controlName])) {
            throw new FormException('jelix~formserr.unknown.control2', array($controlName, $this->sel));
        }

        if (!$this->controls[$controlName]->isContainer()) {
            throw new FormException('jelix~formserr.control.not.container', array($controlName, $this->sel));
        }

        $values = $this->container->data[$controlName];
        if (!is_array($values) && $values != '') {
            throw new FormException('jelix~formserr.value.not.array', array($controlName, $this->sel));
        }

        if (!$this->container->formId && !$primaryKey) {
            throw new FormException('jelix~formserr.formid.undefined.for.dao', array($controlName, $this->sel));
        }

        if ($primaryKey === null) {
            $primaryKey = $this->container->formId;
        }

        if (!is_array($primaryKey)) {
            $primaryKey = array($primaryKey);
        }

        $dao = jDao::create($daoSelector, $dbProfile);
        $daorec = $dao->createRecord();

        $conditions = jDao::createConditions();
        if ($primaryKeyNames) {
            $pkNamelist = $primaryKeyNames;
        } else {
            $pkNamelist = $dao->getPrimaryKeyNames();
        }

        foreach ($primaryKey as $k => $pk) {
            $conditions->addCondition($pkNamelist[$k], '=', $pk);
            $daorec->{$pkNamelist[$k]} = $pk;
        }

        $dao->deleteBy($conditions);
        if (is_array($values)) {
            $valuefield = $pkNamelist[$k + 1];
            foreach ($values as $value) {
                $daorec->{$valuefield} = $value;
                $dao->insert($daorec);
            }
        }
    }

    /**
     * return list of errors found during the check.
     *
     * @return array
     *
     * @see jFormsBase::check()
     */
    public function getErrors()
    {
        return $this->container->errors;
    }

    /**
     * set an error message on a specific field.
     *
     * @param string $field the field name
     * @param string $mesg  the error message string
     */
    public function setErrorOn($field, $mesg)
    {
        $this->container->errors[$field] = $mesg;
    }

    /**
     * @param string          $name  the name of the control/data
     * @param string|string[] $value the data value
     *
     * @throws FormException
     */
    public function setData($name, $value)
    {
        if (!isset($this->controls[$name])) {
            throw new FormException(
                'jelix~formserr.unknown.control2',
                array($name, $this->sel)
            );
        }

        $this->controls[$name]->setData($value);
    }

    /**
     * @param string $name the name of the  control/data
     *
     * @return array|string the data value
     */
    public function getData($name)
    {
        if (isset($this->container->data[$name])) {
            return $this->container->data[$name];
        }

        return '';
    }

    /**
     * @param string $name the name of the  control/data
     *
     * @return bool true if there is a data with this name
     */
    public function hasData($name)
    {
        return array_key_exists($name, $this->container->data);
    }

    /**
     * @return array form data
     */
    public function getAllData()
    {
        return $this->container->data;
    }

    /**
     * deactivate (or reactivate) a control
     * When a control is deactivated, it is not displayes anymore in the output form.
     *
     * @param string $name         the name of the control
     * @param bool   $deactivation TRUE to deactivate, or FALSE to reactivate
     *
     * @throws FormException
     */
    public function deactivate($name, $deactivation = true)
    {
        if (!isset($this->controls[$name])) {
            throw new FormException('jelix~formserr.unknown.control2', array($name, $this->sel));
        }

        $this->controls[$name]->deactivate($deactivation);
    }

    /**
     * check if a control is activated.
     *
     * @param string $name the control name
     *
     * @return bool true if it is activated
     */
    public function isActivated($name)
    {
        return $this->container->isActivated($name);
    }

    /**
     * set a control readonly or not.
     *
     * @param $name
     * @param bool $r true if you want read only
     *
     * @throws FormException
     */
    public function setReadOnly($name, $r = true)
    {
        if (!isset($this->controls[$name])) {
            throw new FormException('jelix~formserr.unknown.control2', array($name, $this->sel));
        }

        $this->controls[$name]->setReadOnly($r);
    }

    /**
     * check if a control is readonly.
     *
     * @param mixed $name
     *
     * @return bool true if it is readonly
     */
    public function isReadOnly($name)
    {
        return $this->container->isReadOnly($name);
    }

    /**
     * @return \Jelix\Forms\FormDataContainer
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * @return \Jelix\Forms\Controls\AbstractControl[]
     */
    public function getRootControls()
    {
        return $this->rootControls;
    }

    /**
     * @return \Jelix\Forms\Controls\AbstractControl[]
     */
    public function getControls()
    {
        return $this->controls;
    }

    /**
     * @param string $name the control name you want to get
     *
     * @return \Jelix\Forms\Controls\AbstractControl
     *
     * @since 1.0
     */
    public function getControl($name)
    {
        if (isset($this->controls[$name])) {
            return $this->controls[$name];
        }

        return null;
    }

    /**
     * @return \Jelix\Forms\Controls\SubmitControl[]
     */
    public function getSubmits()
    {
        return $this->submits;
    }

    /**
     * @return \Jelix\Forms\Controls\HiddenControl[]
     *
     * @since 1.1
     */
    public function getHiddens()
    {
        return $this->hiddens;
    }

    /**
     * @return \Jelix\Forms\Controls\HtmlEditorControl[]
     *
     * @since 1.1
     */
    public function getHtmlEditors()
    {
        return $this->htmleditors;
    }

    /**
     * @return \Jelix\Forms\Controls\WikiEditorControl[]
     *
     * @since 1.2
     */
    public function getWikiEditors()
    {
        return $this->wikieditors;
    }

    /**
     * @return \Jelix\Forms\Controls\Upload2Control[]|\Jelix\Forms\Controls\UploadControl[]
     *
     * @since 1.2
     */
    public function getUploads()
    {
        return $this->uploads;
    }

    /**
     * call this method after initialization of the form, in order to track
     * modified controls.
     *
     * @since 1.1
     */
    public function initModifiedControlsList()
    {
        $this->container->originalData = $this->container->data;
    }

    /**
     * returns the old values of the controls which have been modified since
     * the call of the method initModifiedControlsList().
     *
     * @return array key=control id,  value=old value
     *
     * @since 1.1
     */
    public function getModifiedControls()
    {
        if (count($this->container->originalData)) {
            $result = array();

            $orig = &$this->container->originalData;

            foreach ($this->controls as $ref => $ctrl) {
                if (!array_key_exists($ref, $orig)) {
                    continue;
                }

                if ($ctrl->isModified()) {
                    $result[$ref] = $orig[$ref];
                }
            }

            return $result;
        }

        return $this->container->data;
    }

    /**
     * @return \Jelix\Forms\Controls\ResetControl the reset object
     */
    public function getReset()
    {
        return $this->reset;
    }

    /**
     * @return string the formId
     */
    public function id()
    {
        return $this->container->formId;
    }

    /**
     * @return bool
     */
    public function hasUpload()
    {
        return count($this->uploads) > 0;
    }

    /**
     * @param string $buildertype the type name of a form builder.
     *
     * @return \Jelix\Forms\Builder\BuilderBase
     * @throws FormException
     *
     */
    public function getBuilder($buildertype)
    {
        if ($buildertype == '') {
            $buildertype = $plugintype = 'html';
        } else {
            $plugintype = $buildertype;
        }

        if (isset($this->builders[$buildertype])) {
            return $this->builders[$buildertype];
        }

        /** @var \Jelix\Forms\Builder\BuilderBase $o */
        $o = jApp::loadPlugin($plugintype, 'formbuilder', '.formbuilder.php', $plugintype.'FormBuilder', $this);

        if ($o) {
            $this->builders[$buildertype] = $o;

            return $o;
        }

        throw new FormException('jelix~formserr.invalid.form.builder', array($buildertype, $this->sel));
    }

    /**
     * save an uploaded file in the given directory. the given control must be
     * an upload control of course.
     *
     * @param string $controlName   the name of the upload control
     * @param string $path          path of the directory where to store the file. If it is not given,
     *                              it will be stored under the var/uploads/_modulename~formname_/ directory
     * @param string $alternateName a new name for the file. If it is not given, the file
     *                              while be stored with the original name
     *
     * @throws FormException
     *
     * @return bool true if the file has been saved correctly
     */
    public function saveFile($controlName, $path = '', $alternateName = '', $deletePreviousFile = true)
    {
        if ($path == '') {
            $path = jApp::varPath('uploads/'.$this->sel.'/');
        } elseif (substr($path, -1, 1) != '/') {
            $path .= '/';
        }

        if (!isset($this->controls[$controlName]) || $this->controls[$controlName]->type != 'upload') {
            throw new FormException('jelix~formserr.invalid.upload.control.name', array($controlName, $this->sel));
        }

        jFile::createDir($path);

        return $this->controls[$controlName]->saveFile($path, $alternateName, $deletePreviousFile);
    }

    /**
     * save all uploaded file in the given directory.
     *
     * @param string $path path of the directory where to store the file. If it is not given,
     *                     it will be stored under the var/uploads/_modulename~formname_/ directory
     */
    public function saveAllFiles($path = '')
    {
        if ($path == '') {
            $path = jApp::varPath('uploads/'.$this->sel.'/');
        } elseif (substr($path, -1, 1) != '/') {
            $path .= '/';
        }

        if (count($this->uploads)) {
            jFile::createDir($path);
        }

        foreach ($this->uploads as $ref => $ctrl) {
            $ctrl->saveFile($path);
        }
    }

    /**
     * add a control to the form.
     *
     * @param \Jelix\Forms\Controls\AbstractControl $control the control to add
     */
    public function addControl($control)
    {
        $this->rootControls[$control->ref] = $control;
        $this->addChildControl($control);
    }

    /**
     * add a control to the form, before the specified control.
     *
     * @param \Jelix\Forms\Controls\AbstractControl $control the control to add
     * @param string        $ref     The ref of the control the new control should be inserted before
     *
     * @since 1.1
     */
    public function addControlBefore($control, $ref)
    {
        if (isset($this->rootControls[$ref])) {
            $controls = array();
            foreach ($this->rootControls as $k => $c) {
                if ($k == $ref) {
                    $controls[$control->ref] = null;
                }
                $controls[$k] = $c;
            }
            $this->rootControls = $controls;
        }
        $this->addControl($control);
    }

    public function removeControl($name)
    {
        if (!isset($this->rootControls[$name])) {
            return;
        }
        unset($this->rootControls[$name], $this->controls[$name], $this->submits[$name]);

        if ($this->reset && $this->reset->ref == $name) {
            $this->reset = null;
        }
        unset($this->uploads[$name], $this->hiddens[$name], $this->htmleditors[$name], $this->wikieditors[$name], $this->container->data[$name]);
    }

    /**
     * declare a child control to the form. The given control should be a child of an other control.
     *
     * @param \Jelix\Forms\Controls\AbstractControl $control
     */
    public function addChildControl($control)
    {
        $this->controls[$control->ref] = $control;

        switch ($control->type) {
            case 'submit':
                $this->submits[$control->ref] = $control;

                break;

            case 'reset':
                $this->reset = $control;

                break;

            case 'upload':
                $this->uploads[$control->ref] = $control;

                break;

            case 'hidden':
                $this->hiddens[$control->ref] = $control;

                break;

            case 'htmleditor':
                $this->htmleditors[$control->ref] = $control;

                break;

            case 'wikieditor':
                $this->wikieditors[$control->ref] = $control;

                break;
        }
        $control->setForm($this);

        if (!array_key_exists($control->ref, $this->container->data)) {
            if ($control->datatype instanceof jDatatypeDateTime && $control->defaultValue == 'now') {
                $dt = new jDateTime();
                $dt->now();
                $this->container->data[$control->ref] = $dt->toString($control->datatype->getFormat());
            } else {
                $this->container->data[$control->ref] = $control->defaultValue;
            }
        }

        if ($control instanceof  \Jelix\Forms\Controls\AbstractGroupsControl ||$control instanceof \jFormsControlGroups) {
            foreach ($control->getChildControls() as $ctrl) {
                $this->addChildControl($ctrl);
            }
        }
    }

    /**
     * generate a new token for security against CSRF
     * a builder should call it and create for example an hidden input
     * so jForms could verify it after the submit.
     *
     * @return string the token
     *
     * @since 1.1.2
     */
    public function createNewToken()
    {
        if ($this->container->token == '') {
            if (is_callable('random_bytes')) {
                $tok = bin2hex(random_bytes(20));
            } else {
                $tok = md5($this->container->formId.time().session_id());
            }

            return $this->container->token = $tok;
        }

        return $this->container->token;
    }

    /**
     * Check if the valid token is the token created during the display of the form.
     *
     * @param string $receivedToken
     *
     * @return bool
     *
     * @since 1.7.0
     */
    public function isValidToken($receivedToken)
    {
        // TODO we could check also Origin and Referer header
        return $this->container->token === $receivedToken;
    }
}
