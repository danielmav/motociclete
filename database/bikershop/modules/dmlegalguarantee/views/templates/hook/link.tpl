{* Declanșator „Garanția legală" — link la imaginea oficială (fallback fără JS), JS îl transformă în modal. *}
<div class="dmlg dmlg--{$dmlg_place|escape:'html':'UTF-8'}">
    <a class="dmlg__link" href="{$dmlg_img|escape:'html':'UTF-8'}" target="_blank" rel="noopener" data-dmlg-open>
        <svg class="dmlg__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3l7 3v5c0 4.6-3 8.4-7 10-4-1.6-7-5.4-7-10V6z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M8.8 12.2l2.2 2.2 4.2-4.4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span>{if $dmlg_place == 'nav'}Garanția legală{else}Drepturile tale privind garanția legală{/if}</span>
    </a>
    {if $dmlg_place != 'nav'}
        <span class="dmlg__more">· <a href="{$dmlg_ye_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">europa.eu/youreurope/garanții</a></span>
    {/if}
</div>
