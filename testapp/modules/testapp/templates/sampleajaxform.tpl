{form $form,'sampleform:save', array(), $builder, array('plugins'=>array('explanation' => 'textarea_as_input_html'))}
    {formcontrols}
    <div>{ctrl_label '', '%s: '} {ctrl_control}</div>
    {/formcontrols}
<p>{formreset} {formsubmit}</p>
{/form}
