{extends file="layout.tpl"}

{block name="content"}
    <section class="panel">
        <p class="eyebrow">Ошибка {$status}</p>
        <h1>{$pageTitle}</h1>
        <p>{$message}</p>
        <a href="/">На главную</a>
    </section>
{/block}
