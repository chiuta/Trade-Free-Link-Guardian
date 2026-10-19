# Contribuir para o Trade-Free Link Guardian

Obrigado por considerar uma contribuição. Algumas coisas úteis a saber antes de começar.

## A estrutura de ficheiro único — deliberada, não acidental

Todo o código do plugin está num único ficheiro PHP, `trade-free-link-guardian.php`. Não é uma escolha de ocasião, é a filosofia do projeto: «sem fronteiras» — sem passo de compilação, sem dependências externas a instalar, funcional apenas copiando um ficheiro. Faz parte de um portefólio mais vasto de aplicações de ficheiro único construídas sobre o mesmo princípio.

Se vem com experiência de desenvolvimento WordPress moderno (espaços de nomes, autoloading, um ficheiro por classe), a estrutura pode parecer invulgar. É intencional, não uma dívida técnica a resolver com um grande refactor. Uma contribuição que proponha dividir o ficheiro em vários não será aceite só por esse motivo — mas uma contribuição que melhore algo *dentro* desta estrutura (uma função nova, uma correção, um teste adicional) é bem-vinda.

## Orientação rápida no ficheiro

No início do ficheiro existe um índice («TABLE OF CONTENTS») — cada classe tem um texto de pesquisa exato (por exemplo `SECTION: TFLG_Checker`) que pode procurar (Ctrl+F / Cmd+F) em qualquer editor para ir diretamente até ela. Preferível aos números de linha, que ficariam desatualizados a cada alteração.

As treze classes, em resumo:
- `TFLG_Util` — funções auxiliares sem estado, usadas em todo o lado
- `TFLG_DB` — o esquema e todas as consultas diretas à base de dados
- `TFLG_Scanner` — extrai ligações do conteúdo
- `TFLG_Checker` — faz o pedido HTTP que decide se uma ligação está viva
- `TFLG_Fixer` — as seis correções determinísticas, aplicar/anular
- `TFLG_List_Table` — a tabela do ecrã Links (apenas admin)
- `TFLG_Admin` — o HTML de cada página de administração, o menu, CSS/JS
- `TFLG_Ajax` — cada ponto de acesso `wp_ajax_`
- `TFLG_Network_Admin` — a vista ao nível da rede, apenas multisite
- `TFLG_Editor_Check` — o aviso ao guardar uma publicação
- `TFLG_404_Monitor` — o registo passivo de erros 404
- `TFLG_Cron` — a análise agendada opcional
- `TFLG_Plugin` — ativação, desativação, desinstalação, o construtor que liga tudo

## Testes — obrigatórios, não opcionais

Cada função ou correção nova deve ser acompanhada de testes. Os testes existentes não correm através de um PHPUnit clássico instalado como dependência — são scripts PHP autónomos que extraem o código real do ficheiro principal (contando chavetas, sem presumir o texto) e o executam isoladamente, com stubs mínimos para as funções WordPress necessárias. Para a lógica JavaScript, os testes reais correm com Node.js — os que tocam na manipulação do DOM (por exemplo a ordenação no Book View) usam `jsdom` para um ambiente DOM real, não uma simulação superficial.

Um teste que apenas confirma o que já espera que aconteça não ajuda muito. Os testes mais úteis neste projeto são os que verificam exatamente o caso-limite fácil de errar — por exemplo, que uma função de pré-visualização nunca escreve na base de dados, ou que um índice se mantém sincronizado com o conteúdo real após uma reordenação.

## Segurança — verificação direta, não presunção

Qualquer consulta SQL nova deve usar `$wpdb->prepare()` com parâmetros reais, não interpolação de cadeias. Qualquer ação que altere o estado (AJAX, formulário) deve verificar tanto a capacidade (`current_user_can`) como um nonce. Qualquer conteúdo apresentado que possa vir de uma fonte menos fiável do que um administrador — o título de uma página externa, um cabeçalho HTTP enviado por um visitante — deve ser escapado na saída, e não apenas higienizado na entrada.

## Sem telemetria, sem exceções

Nada neste plugin envia dados para serviços externos sem uma ação explícita do utilizador. Uma contribuição que adicione qualquer forma de reporte automático para um servidor externo, por mais bem-intencionada que seja, não será aceite.

## Como enviar uma contribuição

O projeto será publicado no WordPress.org, com o fluxo SVN padrão da plataforma. Para contribuições de código, o mais simples é descrever com clareza a alteração proposta e o seu motivo, juntamente com os testes correspondentes, diretamente ao autor (veja os dados de contacto no cabeçalho do plugin ou em readme.txt).
