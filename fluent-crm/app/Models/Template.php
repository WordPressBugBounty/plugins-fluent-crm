<?php

namespace FluentCrm\App\Models;

use FluentCrm\App\Services\Libs\Parser\Parser;

/**
 *  Template Model - DB Model for Email templates
 *
 *  Database Model
 *
 * @package FluentCrm\App\Models
 *
 * @version 1.0.0
 */

class Template extends Model
{
    const CREATED_AT = 'post_date';

    const UPDATED_AT = 'post_modified';

    protected $table = 'posts';

    protected $primaryKey = 'ID';

    /**
     * Limit every query to FluentCRM's own template post types.
     *
     * The model sits on wp_posts, so without this a template id from a request could resolve to
     * any page, post or product, letting a user with only the Email Templates permission read,
     * overwrite or delete it. Inserts are not affected.
     */
    public static function boot()
    {
        parent::boot();

        static::addGlobalScope('fluentcrm_template_post_types', function ($builder) {
            $builder->whereIn('post_type', [fluentcrmTemplateCPTSlug(), fluentcrmCampaignTemplateCPTSlug()]);
        });
    }

    public function scopeEmailTemplates($query, $types = ['publish'])
    {
        return $query->where(
            'post_type', fluentcrmTemplateCPTSlug()
        )->whereIn('post_status', $types);
    }

    public function scopeCampaignTemplate($query)
    {
        return $query->where(
            'post_type', fluentcrmCampaignTemplateCPTSlug()
        )->where('post_status', 'publish');
    }

    public function campaign()
    {
        return $this->hasOne(__NAMESPACE__.'\\'.'Campaign', 'template_id', 'ID');
    }

    public function render($content = null)
    {
        $content = $content ?: $this->post_content;

        return Parser::parse($content, []);
    }
}
