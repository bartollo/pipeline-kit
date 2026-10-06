# Laravel + Claude Code --- Pipeline de Qualidade, Sloppy, Code Review e IA

Este documento define um pipeline recomendado para desenvolver
aplicações **PHP/Laravel com Claude Code**, combinando ferramentas
determinísticas, análise específica para código produzido por agentes,
testes, code review e CI.

> **A IA implementa. Sloppy vigia o agente. As ferramentas verificam.
> Outro contexto revisa. O CI valida.**

------------------------------------------------------------------------

## 1. Objetivo

O Claude não deve decidir sozinho se uma tarefa está concluída.

O código só é considerado pronto depois de passar por múltiplas camadas
independentes:

``` text
Claude Code
    │
    ├── Laravel Boost / MCP
    │
    ▼
Implementação
    │
    ├── Sloppy hook após edição
    │       └── finding novo → Claude corrige imediatamente
    │
    ▼
Laravel Pint
    │
    ▼
Larastan / PHPStan
    │
    ▼
Pest
    │
    ├── Feature / Unit
    ├── Architecture Tests
    └── Type Coverage
    │
    ▼
Composer Audit
    │
    ▼
Sloppy Diff / Review
    │
    ▼
composer quality
    │
    ▼
AI Code Review independente
    │
    ▼
GitHub Actions
    │
    ├── quality
    └── Sloppy CI / annotations
    │
    ▼
Human Review
    │
    ▼
Merge
```

------------------------------------------------------------------------

## 2. Stack recomendada

### Framework

-   PHP 8.3+
-   Laravel
-   Composer

### IA

-   Claude Code
-   Laravel Boost
-   MCP
-   Sloppy

### Qualidade

-   Laravel Pint
-   Larastan / PHPStan
-   Sloppy
-   Composer Audit

### Testes

-   Pest
-   Feature Tests
-   Unit Tests
-   Architecture Tests
-   Type Coverage
-   Mutation Testing

### Review

-   Sloppy Diff
-   Sloppy Review
-   AI Code Review
-   Human Review

### Automação

-   Composer Scripts
-   Claude Code Hooks
-   GitHub Actions

### Opcional

-   Rector
-   Deptrac

------------------------------------------------------------------------

## 3. Instalação

Instale as ferramentas principais:

``` bash
composer require --dev \
    laravel/pint \
    larastan/larastan \
    pestphp/pest \
    pestphp/pest-plugin-laravel \
    pestphp/pest-plugin-type-coverage \
    heyosseus/sloppy
```

Depois configure a integração do Sloppy com Claude Code:

``` bash
vendor/bin/sloppy agents install
```

Em Laravel, o Sloppy também pode ser executado por:

``` bash
php artisan sloppy
```

Configure também o **Laravel Boost** para fornecer ao agente contexto do
framework, documentação e informações compatíveis com as versões
utilizadas no projeto.

------------------------------------------------------------------------

## 4. Por que Sloppy entra no pipeline

O Sloppy é uma camada complementar a Pint, PHPStan e Pest.

Cada ferramenta responde a uma pergunta diferente:

``` text
Pint
└── O código está formatado?

PHPStan / Larastan
└── Os tipos e contratos estáticos estão corretos?

Pest
└── O comportamento esperado funciona?

Architecture Tests
└── As regras arquiteturais verificáveis continuam válidas?

Sloppy
└── O agente introduziu dívida, padrões ruins ou regressões
    típicas de código produzido rapidamente por IA?

AI Code Review
└── Existe algum problema semântico/contextual que as
    ferramentas determinísticas não identificaram?
```

O Sloppy é particularmente útil porque trabalha sobre **novos problemas
introduzidos pela alteração**, evitando que o agente saia corrigindo
dívida histórica que não pertence à tarefa atual.

------------------------------------------------------------------------

## 5. Sloppy dentro do loop do Claude

O Sloppy deve rodar durante o desenvolvimento, e não apenas no CI.

Após instalar os hooks:

``` bash
vendor/bin/sloppy agents install
```

o fluxo esperado é:

``` text
Claude altera arquivo PHP
        │
        ▼
Sloppy analisa a alteração
        │
        ├── finding novo
        │       │
        │       ▼
        │   Claude corrige
        │       │
        │       └──────┐
        │              │
        ▼              │
      limpo ◄───────────┘
        │
        ▼
Claude continua
```

Quando o agente tenta finalizar uma tarefa, novos findings acima do
threshold configurado devem fazê-lo voltar para a correção.

Isso cria um feedback loop muito mais curto:

``` text
gerar → analisar → corrigir → continuar
```

em vez de:

``` text
gerar tudo → abrir PR → descobrir problemas no review
```

------------------------------------------------------------------------

## 6. O que o Sloppy procura

Entre as categorias analisadas estão:

-   complexidade excessiva;
-   god methods;
-   god classes;
-   nesting excessivo;
-   lógica duplicada;
-   copy/paste drift;
-   métodos privados mortos;
-   dependências de construtor não utilizadas;
-   implementações placeholder;
-   exceções engolidas;
-   condições redundantes;
-   comentários narrativos;
-   defensive programming desnecessário;
-   business logic em controllers;
-   validação inline;
-   chamadas externas diretas;
-   models fazendo responsabilidades demais;
-   possíveis N+1;
-   queries dentro de loops;
-   uso de Collection quando a query deveria resolver;
-   `Model::all()` suspeito;
-   excesso de dependências em controllers/services;
-   abstrações desnecessárias;
-   wrappers vazios;
-   abstrações usadas uma única vez;
-   suppressions sem explicação;
-   crescimento de baseline PHPStan/Psalm;
-   testes enfraquecidos para fazer o pipeline passar.

Isso é especialmente relevante em workflows com agentes, pois vários
desses problemas não são necessariamente erros de sintaxe ou de tipos.

------------------------------------------------------------------------

## 7. Laravel Pint

Verificação:

``` bash
vendor/bin/pint --test
```

Correção:

``` bash
vendor/bin/pint
```

O Pint é responsável pela padronização mecânica do código.

Não desperdice o AI Code Review discutindo formatação que o Pint
consegue resolver deterministicamente.

------------------------------------------------------------------------

## 8. Larastan + PHPStan

Exemplo de `phpstan.neon`:

``` neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app

    level: 8

    treatPhpDocTypesAsCertain: false
```

Para projetos novos, evolua progressivamente para:

``` neon
level: 10
```

### Projetos legados

Em projetos existentes:

``` bash
vendor/bin/phpstan analyse --generate-baseline
```

O baseline deve representar dívida histórica.

Nunca regenere o baseline apenas para esconder novos erros.

O Sloppy também pode detectar crescimento indevido de baselines,
adicionando outra proteção contra esse tipo de atalho.

------------------------------------------------------------------------

## 9. Pest

Execute:

``` bash
vendor/bin/pest --parallel
```

Toda alteração comportamental deve possuir testes.

Priorize:

1.  Feature Tests;
2.  Unit Tests para lógica isolada;
3.  Regression Tests.

Teste pelo menos:

-   happy path;
-   validação;
-   autorização;
-   failure paths;
-   edge cases importantes;
-   regressões.

------------------------------------------------------------------------

## 10. Architecture Tests

Exemplo:

``` php
<?php

arch('debug functions')
    ->expect('App')
    ->not->toUse([
        'dd',
        'dump',
        'var_dump',
    ]);

arch('controllers')
    ->expect('App\Http\Controllers')
    ->toHaveSuffix('Controller');

arch('models')
    ->expect('App\Models')
    ->toExtend(\Illuminate\Database\Eloquent\Model::class);

arch()->preset()->php();
arch()->preset()->security();
arch()->preset()->laravel();
```

Architecture Tests transformam decisões arquiteturais em regras
executáveis.

------------------------------------------------------------------------

## 11. Sloppy integrado ao Pest

O Sloppy pode participar diretamente da suite Pest.

Exemplo:

``` php
it('has no new slop on this branch', function (): void {
    expectCleanSloppyDiff('main');
});
```

Isso significa que dívida nova detectada pelo Sloppy pode quebrar a
suite de testes.

O benefício é importante:

``` text
regra do projeto
      ↓
teste executável
      ↓
CI
      ↓
merge bloqueado
```

------------------------------------------------------------------------

## 12. Type Coverage

``` bash
vendor/bin/pest --type-coverage --min=95
```

Para projetos novos, considere evoluir para:

``` bash
vendor/bin/pest --type-coverage --min=100
```

Evite:

``` php
function calculate($value)
```

Prefira, quando apropriado:

``` php
public function calculate(Money $value): Money
```

------------------------------------------------------------------------

## 13. Mutation Testing

Para verificar a qualidade real dos testes:

``` bash
vendor/bin/pest \
    --mutate \
    --parallel \
    --covered-only \
    --min=80
```

Como mutation testing é mais caro, uma estratégia possível é executá-lo:

-   nightly;
-   em PRs críticos;
-   antes de releases;
-   em módulos sensíveis.

------------------------------------------------------------------------

## 14. Composer Audit

Inclua:

``` bash
composer audit
```

A verificação de vulnerabilidades conhecidas deve fazer parte do CI.

------------------------------------------------------------------------

## 15. Sloppy Diff

Durante uma feature branch:

``` bash
vendor/bin/sloppy diff main
```

O objetivo é analisar somente os problemas introduzidos pela branch.

Isso é especialmente importante em projetos legados:

``` text
dívida histórica
      │
      ├── não pertence à tarefa
      │
      └── baseline

mudança atual
      │
      └── não pode introduzir dívida nova
```

------------------------------------------------------------------------

## 16. Sloppy Review

Antes do AI Code Review:

``` bash
vendor/bin/sloppy review main
```

O `review` prioriza os findings por risco, ajudando o reviewer a decidir
onde concentrar atenção.

Fluxo:

``` text
git diff
    │
    ▼
sloppy diff
    │
    ▼
sloppy review
    │
    ▼
AI Code Review
    │
    ▼
Human Review
```

Sloppy não substitui Code Review.

Ele reduz ruído e aponta regiões do diff que merecem atenção.

------------------------------------------------------------------------

## 17. Sloppy Fix

Para findings que podem ser corrigidos mecanicamente:

``` bash
vendor/bin/sloppy fix
```

O Sloppy pode delegar correções mecânicas ao Rector, limitar as
alterações aos arquivos relevantes e executar Pint.

A regra deve ser:

``` text
correção mecânica
      ↓
Sloppy / Rector / Pint

correção semântica
      ↓
Claude / desenvolvedor
      ↓
testes
      ↓
review
```

Nunca permita que uma ferramenta automática altere silenciosamente
regras de negócio apenas para eliminar um finding.

------------------------------------------------------------------------

## 18. Composer Scripts

Exemplo de `composer.json`:

``` json
{
    "scripts": {
        "lint": "pint --test",
        "lint:fix": "pint",
        "analyse": "phpstan analyse --memory-limit=2G",
        "test": "pest --parallel",
        "types": "pest --type-coverage --min=95",
        "sloppy": "sloppy",
        "sloppy:diff": "sloppy diff main",
        "sloppy:review": "sloppy review main",
        "security": "composer audit",
        "quality": [
            "@lint",
            "@analyse",
            "@test",
            "@types",
            "@sloppy:diff",
            "@security"
        ]
    }
}
```

Contrato principal:

``` bash
composer quality
```

Se retornar código diferente de `0`, a tarefa não terminou.

------------------------------------------------------------------------

## 19. AI Code Review

Depois que as verificações determinísticas passarem, execute um Code
Review independente.

Idealmente:

``` text
Claude / contexto A
       │
       ▼
  implementação
       │
       ▼
Sloppy + composer quality
       │
       ▼
Claude / contexto B
       │
       ▼
   CODE REVIEW
```

O reviewer deve receber:

``` text
REQUISITO
+
GIT DIFF
+
CONTEXTO ARQUITETURAL
+
RESULTADOS DOS TESTES
+
FINDINGS DO SLOPPY
```

O segundo contexto não deve assumir que a implementação está correta
apenas porque outro agente a produziu.

------------------------------------------------------------------------

## 20. Checklist do Code Review

### Correctness

-   bugs;
-   lógica invertida;
-   condições incompletas;
-   tratamento incorreto de `null`;
-   estados inválidos;
-   edge cases;
-   concorrência.

### Laravel

-   N+1;
-   eager loading ausente;
-   queries desnecessárias;
-   business logic em controllers;
-   validação fora de Form Requests;
-   autorização ausente;
-   transactions ausentes;
-   jobs que deveriam ser assíncronos.

### Database

-   índices ausentes;
-   migrations problemáticas;
-   foreign keys;
-   unique constraints;
-   race conditions;
-   queries em loops;
-   operações não atômicas.

### Security

-   mass assignment;
-   authorization bypass;
-   IDOR;
-   SQL injection;
-   XSS;
-   exposição de dados sensíveis;
-   secrets;
-   uploads inseguros;
-   validação insuficiente.

### Performance

-   N+1;
-   queries duplicadas;
-   coleções gigantes em memória;
-   falta de paginação;
-   operações síncronas lentas;
-   processamento desnecessário.

### Tests

-   comportamento novo sem teste;
-   assertions fracas;
-   testes que não provam o comportamento;
-   ausência de failure paths;
-   autorização não testada;
-   validação não testada;
-   regressões sem cobertura.

### Architecture

-   abstrações desnecessárias;
-   duplicação;
-   responsabilidades misturadas;
-   dependências inadequadas;
-   regras de domínio em controllers;
-   acoplamento desnecessário.

------------------------------------------------------------------------

## 21. Severidade dos findings

Use:

``` text
BLOCKER
Erro grave que impede merge.

HIGH
Bug, vulnerabilidade ou problema importante.

MEDIUM
Problema real que deve ser corrigido.

LOW
Melhoria válida, mas não crítica.

INFO
Observação ou sugestão opcional.
```

Exemplo:

``` text
[HIGH] Race condition na criação do pagamento

PaymentService.php:82

O código verifica se existe um pagamento e depois cria
um novo registro em duas operações separadas.

Duas requests concorrentes podem passar pela verificação
antes que qualquer uma faça o INSERT.

Recomendação:
Adicionar unique constraint no banco e tratar a colisão,
ou tornar a operação atomicamente segura.
```

------------------------------------------------------------------------

## 22. Code Review deve procurar bugs, não estilo

Evite:

``` text
"Eu escreveria este método de outra forma."

"Prefiro repository aqui."

"Talvez este nome pudesse ser diferente."
```

Priorize:

``` text
BUG
SECURITY
DATA INTEGRITY
RACE CONDITION
PERFORMANCE
MISSING AUTHORIZATION
MISSING VALIDATION
MISSING TEST
ARCHITECTURE VIOLATION
```

Pint já cuida de estilo mecânico.

Sloppy já cobre vários padrões estruturais recorrentes.

O reviewer deve usar seu contexto para procurar problemas que ainda
escaparam dessas camadas.

------------------------------------------------------------------------

## 23. CLAUDE.md

Adicione regras semelhantes a estas ao `CLAUDE.md`:

``` md
# Laravel Development Rules

## General

This is a Laravel application.

Before implementing anything:

1. Understand the existing architecture.
2. Search for existing implementations before creating abstractions.
3. Prefer Laravel conventions over custom solutions.
4. Use Laravel Boost documentation when unsure about framework APIs.
5. Never assume an API exists.

## PHP

- Use strict typing where appropriate.
- Prefer typed properties.
- Always declare parameter and return types.
- Avoid mixed whenever possible.
- Prefer enums over magic strings.
- Do not create unnecessary abstractions.

## Laravel

- Validation belongs in Form Requests.
- Authorization belongs in Policies/Gates.
- Business logic should not live in controllers.
- Avoid N+1 queries.
- Use transactions when multiple writes must be atomic.
- Use queues for slow/out-of-band operations.
- Never call env() outside config files.

## Database

- Migrations must be reversible.
- Add appropriate indexes and constraints.
- Avoid queries inside loops.
- Consider race conditions.
- Protect invariants at database level when appropriate.

## Testing

Every behavioral change must include tests.

Test:
- happy path
- validation
- authorization
- failure paths
- important edge cases
- regressions

## Sloppy

Sloppy is part of the development loop.

- Fix new Sloppy findings introduced by your changes.
- Do not modify unrelated legacy code merely to improve the Sloppy score.
- Do not grow a baseline to hide a new problem.
- Do not weaken tests to satisfy the pipeline.
- Use sloppy diff/review before declaring work complete.
- Mechanical fixes may use sloppy fix, but semantic changes require review.

## Definition of Done

Before saying a task is complete:

1. Run the relevant tests.
2. Run composer quality.
3. Run sloppy review main.
4. Review git diff.
5. Fix valid findings.
6. Run composer quality again.

Never disable PHPStan, Sloppy rules or tests merely to make CI pass.
Never regenerate a baseline to hide a newly introduced problem.
```

------------------------------------------------------------------------

## 24. GitHub Actions + Sloppy CI

O CI deve repetir as verificações independentemente do agente.

O Sloppy possui modo específico para CI:

``` bash
vendor/bin/sloppy ci
```

Ele também pode ser integrado por GitHub Action:

``` yaml
- uses: heyosseus/sloppy-action@v1
  with:
    diff-branch: main
```

Pipeline:

``` text
                   Pull Request
                        │
          ┌─────────────┴─────────────┐
          │                           │
          ▼                           ▼
      Quality CI                  Sloppy CI
          │                           │
     ┌────┼─────┐                     │
     ▼    ▼     ▼                     │
   Pint PHPStan Pest              diff findings
          │                           │
     Types + Audit                annotations
          │                           │
          └─────────────┬─────────────┘
                        ▼
                  AI Code Review
                        │
                        ▼
                   Human Review
                        │
                        ▼
                      Merge
```

Mesmo que Claude execute tudo localmente, o CI deve repetir as
verificações em ambiente limpo.

------------------------------------------------------------------------

## 25. Sloppy Baseline para projetos legados

Para uma base existente:

``` bash
vendor/bin/sloppy baseline
```

A ideia é aceitar explicitamente a dívida atual e impedir dívida nova.

``` text
LEGACY
────────────────────────────
problemas existentes
        │
        ▼
Sloppy baseline
        │
        ▼
estado conhecido

NOVO CÓDIGO
────────────────────────────
Claude altera código
        │
        ▼
Sloppy diff
        │
        ▼
nova dívida?
   │         │
  SIM       NÃO
   │         │
corrigir   continuar
```

Baseline não é licença para aumentar dívida.

------------------------------------------------------------------------

## 26. Sloppy Watch

Durante desenvolvimento interativo:

``` bash
vendor/bin/sloppy watch
```

Pode ser útil em sessões longas com agentes, mantendo feedback contínuo
enquanto arquivos são modificados.

------------------------------------------------------------------------

## 27. MCP

O Sloppy também oferece servidor MCP para agentes que precisam solicitar
scans sob demanda.

Em uma configuração com Laravel Boost + Sloppy, a divisão conceitual
fica:

``` text
Laravel Boost / MCP
└── contexto Laravel
    documentação
    framework knowledge

Sloppy / MCP
└── qualidade da alteração
    dívida introduzida
    riscos estruturais
```

Os dois são complementares.

------------------------------------------------------------------------

## 28. Human Review

AI Code Review e Sloppy não eliminam Human Review.

As ferramentas são boas para detectar sistematicamente:

-   padrões problemáticos;
-   bugs potenciais;
-   dívida nova;
-   regressões;
-   problemas repetitivos;
-   ausência de testes.

O humano continua responsável por questões como:

-   o requisito está correto?
-   essa solução resolve o problema de produto?
-   a complexidade é justificável?
-   essa arquitetura faz sentido no longo prazo?
-   estamos resolvendo o problema certo?

------------------------------------------------------------------------

## 29. Definition of Done

Uma tarefa só é considerada concluída quando:

-   implementação está completa;
-   Sloppy hooks não apontam nova dívida relevante;
-   Pint passa;
-   PHPStan/Larastan passa;
-   Pest passa;
-   novos comportamentos possuem testes;
-   Architecture Tests passam;
-   Type Coverage respeita o mínimo;
-   Composer Audit passa;
-   `sloppy diff main` está aceitável;
-   `sloppy review main` foi analisado;
-   `composer quality` passa;
-   Git Diff foi revisado;
-   AI Code Review foi executado;
-   findings relevantes foram resolvidos;
-   `composer quality` passou novamente;
-   GitHub Actions passou;
-   Sloppy CI passou;
-   Human Review foi realizado quando necessário.

------------------------------------------------------------------------

## 30. Pipeline final

``` text
                        TASK
                          │
                          ▼
                  ┌───────────────┐
                  │   PLANNING    │
                  │ Claude / human│
                  └───────┬───────┘
                          │
                          ▼
                  ┌───────────────┐
                  │ Claude Code   │
                  │ implementer   │
                  └───────┬───────┘
                          │
                          ▼
                  alteração PHP
                          │
                          ▼
                  ┌───────────────┐
                  │ Sloppy Hook   │
                  └───────┬───────┘
                          │
                    finding novo?
                     /         \
                   SIM         NÃO
                    │           │
                    ▼           ▼
             Claude corrige   continuar
                    │           │
                    └─────┬─────┘
                          ▼
                       testes
                          │
                          ▼
                 composer quality
                          │
                    ┌─────┴─────┐
                    │           │
                  FAIL         PASS
                    │           │
                    ▼           ▼
                 corrigir   sloppy review
                    ▲           │
                    │           ▼
                    │     AI CODE REVIEW
                    │           │
                    │      ┌────┴────┐
                    │      │         │
                    └── findings     OK
                                   │
                                   ▼
                            composer quality
                                   │
                                   ▼
                            GitHub Actions
                              │         │
                              ▼         ▼
                         Quality CI  Sloppy CI
                              │         │
                              └────┬────┘
                                   ▼
                              Human Review
                                   │
                                   ▼
                                  MERGE
```

------------------------------------------------------------------------

## 31. Regra fundamental

Não queremos:

``` text
Claude implementa
       ↓
Claude olha o próprio código
       ↓
"parece correto"
       ↓
Merge
```

Também não queremos depender exclusivamente de outro LLM para fiscalizar
o primeiro.

Queremos:

``` text
Claude implementa
       ↓
Sloppy observa cada alteração
       ↓
Pint
       ↓
Larastan / PHPStan
       ↓
Pest
       ↓
Architecture Tests
       ↓
Type Coverage
       ↓
Security Audit
       ↓
Sloppy Diff / Review
       ↓
AI Reviewer independente
       ↓
CI independente
       ↓
Sloppy CI
       ↓
Human Review
       ↓
Merge
```

O ponto central é criar **feedback determinístico ao redor da IA**.

> **Claude implementa. Laravel Boost fornece contexto. Sloppy detecta a
> dívida típica do agente enquanto ele trabalha. Pint padroniza. PHPStan
> analisa tipos. Pest verifica comportamento. Architecture Tests
> protegem regras estruturais. Sloppy Review prioriza riscos no diff. Um
> segundo contexto faz Code Review. O CI valida tudo novamente. Humanos
> continuam responsáveis pelas decisões de engenharia.**

------------------------------------------------------------------------

## Referência

Sloppy: https://github.com/Heyosseus/sloppy
