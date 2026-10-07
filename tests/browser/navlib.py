"""Auxiliar dos testes: abre o menu lateral (☰) e escolhe uma aba, como faria um utilizador.
Espera que o menu esteja REALMENTE fechado antes de o abrir (ele demora ~220 ms a fechar) e REALMENTE aberto antes de clicar."""
async def nav(pg, name):
    await pg.wait_for_function("(()=>{const m=document.querySelector('#main-menu');return document.documentElement.classList.contains('menu-open')||getComputedStyle(m).visibility==='hidden'})()", timeout=5000)
    if not await pg.evaluate("document.documentElement.classList.contains('menu-open')"):
        await pg.click('#menu-btn')
        await pg.wait_for_function("(()=>{const m=document.querySelector('#main-menu');return document.documentElement.classList.contains('menu-open')&&getComputedStyle(m).visibility==='visible'&&['none','matrix(1, 0, 0, 1, 0, 0)'].includes(getComputedStyle(m).transform)})()", timeout=5000)
    await pg.click(f'#main-menu [data-section={name}]'); await pg.wait_for_timeout(300)
