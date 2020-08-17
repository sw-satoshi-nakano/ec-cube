<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Eccube\Form\Type;

use Eccube\Common\EccubeConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class KanaType extends AbstractType
{
    /**
     * @var \Eccube\Common\EccubeConfig
     */
    protected $eccubeConfig;

    /**
     * KanaType constructor.
     *
     * @param EccubeConfig $eccubeConfig
     */
    public function __construct(EccubeConfig $eccubeConfig)
    {
        $this->eccubeConfig = $eccubeConfig;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        // ひらがなをカタカナに変換する
        // 引数はmb_convert_kanaのもの
        $builder->addEventSubscriber(new \Eccube\Form\EventListener\ConvertKanaListener('CV'));
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
<<<<<<< HEAD
        $resolver->setDefaults(array(
            'lastname_options' => array(
                'attr' => array(
                    'placeholder' => 'Kana01',
                ),
                'constraints' => array(
                    new Assert\Regex(array(
                        'pattern' => "/^[ァ-ヶｦ-ﾟー]+$/u",
                        'message' => 'form.type.kana.lastname.nothasspace'
                    )),
                    new Assert\Length(array(
                        'max' => $this->config['kana_len'],
                    )),
                ),
            ),
            'firstname_options' => array(
                'attr' => array(
                    'placeholder' => 'Kana02',
                ),
                'constraints' => array(
                    new Assert\Regex(array(
                        'pattern' => "/^[ァ-ヶｦ-ﾟー]+$/u",
                        'message' => 'form.type.kana.firstname.nothasspace'
                    )),
                    new Assert\Length(array(
                        'max' => $this->config['kana_len'],
                    )),
                ),
            ),
        ));
=======
        $resolver->setDefaults([
            'lastname_options' => [
                'attr' => [
                    'placeholder' => 'common.last_name_kana',
                ],
                'constraints' => [
                    new Assert\Regex([
                        'pattern' => '/^[ァ-ヶｦ-ﾟー]+$/u',
                        'message' => 'form_error.kana_only',
                    ]),
                    new Assert\Length([
                        'max' => $this->eccubeConfig['eccube_kana_len'],
                    ]),
                ],
            ],
            'firstname_options' => [
                'attr' => [
                    'placeholder' => 'common.first_name_kana',
                ],
                'constraints' => [
                    new Assert\Regex([
                        'pattern' => '/^[ァ-ヶｦ-ﾟー]+$/u',
                        'message' => 'form_error.kana_only',
                    ]),
                    new Assert\Length([
                        'max' => $this->eccubeConfig['eccube_kana_len'],
                    ]),
                ],
            ],
        ]);
>>>>>>> 2c09ba75d7b7fba1a3b27dbc46b98417f7fffe0d
    }

    /**
     * {@inheritdoc}
     */
    public function getParent()
    {
        return NameType::class;
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix()
    {
        return 'kana';
    }
}
