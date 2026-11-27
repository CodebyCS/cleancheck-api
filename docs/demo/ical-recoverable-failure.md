# Guião: demo iCal com falha recuperável

Mostra, com um calendário fictício, a criação de reservas e tarefas, uma alteração, uma falha que não perde dados, a recuperação e um cancelamento. Duração: ~6 minutos.

O calendário vem do [icalSandbox](https://icalsandbox.com), que serve ficheiros `.ics` em `https://` e deixa trocar o conteúdo. Os ficheiros a usar estão em `Docs/demo/ical/`.

## Véspera

1. Na API: `git pull`, `php artisan migrate` e `php artisan db:seed` (utilizadores e imóvel de demo).
2. Confirmar as datas dos três ficheiros: as reservas têm de ser **futuras** no dia da demo. Se não forem, mudar o mês em todos por igual.
3. No icalSandbox, criar **um** feed e importar `1-base.ics`. Guardar o link público (`https://icalsandbox.com/ical/<id>.ics`) e o *secret* num ficheiro fora do repositório. O *secret* não vai para o repo nem para slides.
4. No ensaio, confirmar que um id inexistente no icalSandbox responde 404 (passo 4 da demo).
5. Ensaiar a demo inteira uma vez e, no fim, voltar a importar `1-base.ics`. Um feed sem uso expira ao fim de 30 dias.
6. Confirmar que o imóvel de demo tem um prestador responsável, para as tarefas lhe ficarem atribuídas.

## No dia, antes de começar

- API: `php artisan serve`, `php artisan queue:work` e `php artisan schedule:work`, cada um na sua janela.
- Web aberto em **Fontes iCal**, com sessão de supervisor. Telemóvel com a app aberta em **Avisos**, com sessão do prestador.
- Testar a rede da sala: abrir o link do feed no browser deve descarregar o `.ics`.

## Demo

| # | Ação | O que se vê |
|---|---|---|
| 1 | Em Fontes iCal, **+ Nova fonte** com o link do feed. **Sincronizar agora** | 3 reservas novas; o bloqueio do Airbnb é ignorado. Em Reservas do imóvel e em Tarefas aparecem as 3 limpezas |
| 2 | **Sincronizar agora** outra vez | 0 novas: repetir não duplica |
| 3 | No icalSandbox, importar `2-alteracao.ics`. Sincronizar | 1 atualizada (demo-0004 passa a 27–31/12). A tarefa é reagendada e o prestador recebe o aviso no telemóvel em ≤ 30 s |
| 4 | **Trocar link** para o mesmo endereço com outro id (inexistente). Sincronizar | **Falhou**: "respondeu com o estado 404", com os passos de recuperação. As reservas e tarefas continuam lá |
| 5 | **Trocar link** de volta para o link certo. Sincronizar | **Sincronizada** de novo, sem duplicados: a falha foi recuperada |
| 6 | No icalSandbox, importar `3-cancelamento.ics`. Sincronizar | 1 cancelada (demo-0002). A tarefa é cancelada e o prestador é avisado |

Mensagens para o júri:

- Uma falha do Airbnb fica registada com o motivo e **nunca apaga reservas**.
- Só uma leitura completa e com sucesso pode cancelar; o Airbnb não envia cancelamentos, a reserva desaparece do ficheiro.
- O iCal do Airbnb não traz nome nem número de hóspedes, e a aplicação não os inventa.
- Testado em escala: 400 calendários, 0 perdas e 0 duplicados (`Docs/benchmarks/ical-sync.md`).

## Plano B (sem rede ou sem icalSandbox)

Em **Calendário e reservas**, importar à mão `1-base.ics` duas vezes: mostra a criação, as tarefas e a repetição sem duplicados. A importação manual não aplica alterações nem cancelamentos (só a sincronização pelo link o faz), por isso a falha recuperável fica explicada com o histórico de sincronizações e os gráficos do benchmark.
